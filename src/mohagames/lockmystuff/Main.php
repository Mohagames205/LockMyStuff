<?php

/*
Author: Mohamed
Discord: Mohamed#0710

It is not allowed to remove this reference. Please read the LICENSE.
*/

namespace mohagames\lockmystuff;

use pocketmine\block\Door;
use pocketmine\block\IronDoor;
use pocketmine\block\Trapdoor;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\item\StringItem;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\tile\Chest;
use pocketmine\updater\UpdateCheckTask;
use pocketmine\utils\TextFormat;
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\ItemIds;
use pocketmine\utils\Config;
use pocketmine\block\Block;
use pocketmine\level\Level;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use SQLite3;


class Main extends PluginBase implements Listener
{
    private $lockSession = array();
    private $handle;
    private $unlockSession = array();
    private $config;
    private $itemID;
    private $infoSession = array();
    private $message;

    public function onEnable(): void
    {
        $default_messages = [
            "key-received" => "§dYou received the key succesfully! Please check your inventory.",
            "touch-lock-info" => "§cPlease touch the item you want to lock.",
            "cant-place-key" => "§cIt looks like you were trying to place a key",
            "outdated-key" => "§cIt looks like your key is outdated use /makekey {keyname} to create a new key.",
            "missing-name" => "§cMissing item-name! Usage: /lock [name]",
            "lock-removed" => "§aThe block has been unlocked",
            "touch-unlock-info" => "§aPlease touch the item you want to unlock.",
            "makekey-missing-argument" => "§aMissing key-name! Usage: /makekey [name]",
            "touch-info" => "§aPlease touch the item you want info about.",
            "locked-successfully" => "§aThe door has been locked successfully!",
            "block-already-locked" => "§cThis block is already locked!",
            "block-locked" => "§4This block is locked.",
            "block-deny-break" => "§4You can't break this block"
        ];

        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, array("version" => "1.0.0", "key-item" => ItemIds::TRIPWIRE_HOOK, "messages" => $default_messages));
        $this->itemID = $this->config->get("key-item");
        $this->handle = new SQLite3($this->getDataFolder() . "doors.db");
        $this->handle->query("CREATE TABLE IF NOT EXISTS doors(door_id INTEGER PRIMARY KEY AUTOINCREMENT,door_name TEXT,location TEXT, world TEXT)");
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->message = $this->config->get("messages");
    }

    /**
     * @param CommandSender $sender
     * @param Command $command
     * @param string $label
     * @param array $args
     * @return bool
     */
    public function onCommand(CommandSender $sender, Command $command, String $label, array $args): bool
    {
        if($sender instanceof Player){
            switch ($command->getName()) {
                case "lock":
                    if (isset($args[0])) {
                        $this->lockSession[$sender->getName()] = $args[0];
                        $sender->sendMessage($this->message["touch-lock-info"]);
                    } else {
                        $sender->sendMessage($this->message["missing-name"]);

                    }

                    return true;

                case "unlock":
                    if(isset($args[0])){
                        $this->unlock($args[0]);
                        $sender->sendMessage($this->message["lock-removed"]);
                    }
                    else{
                        $this->unlockSession[$sender->getName()] = true;
                        $sender->sendMessage($this->message["touch-unlock-info"]);

                    }
                    return true;

                case "makekey":
                    if (isset($args[0])) {
                        $item = ItemFactory::get($this->itemID);
                        $item->clearCustomName();
                        $item->setCustomName($args[0]);
                        $sender->getInventory()->addItem($item);
                    } else {
                        $sender->sendMessage($this->message["makekey-missing-argument"]);
                    }
                    return true;

                case "lockedinfo":
                    $this->infoSession[$sender->getName()] = true;
                    $sender->sendMessage($this->message["touch-info"]);
                    return true;

                default:
                    return false;
            }
        }
        else{
            $this->getLogger()->info("Please execute this command in-game");
        }

    }


    /**
     * @param BlockPlaceEvent $event
     */
    public function keyPlace(BlockPlaceEvent $event){
        if($event->getBlock()->getItemId() == $this->itemID && $event->getBlock()->getName() != $event->getItem()->getName() && !empty($event->getItem()->getLore())){
            $event->getPlayer()->sendMessage($this->message["cant-place-key"]);
            $event->setCancelled();
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function basicLock(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $block = $event->getBlock();
        if ($block instanceof Door || $block instanceof \pocketmine\block\Chest || $block instanceof Trapdoor){
            if (isset($this->lockSession[$player->getName()])){
                //Adding key to inventory
                if($this->isLockedDown($event->getBlock(), $event->getItem()) === null){
                    $item = ItemFactory::get($this->itemID);
                    $item->clearCustomName();
                    $item->setCustomName($this->lockSession[$player->getName()]);
                    $item->setLore(["Key: ".$this->lockSession[$player->getName()]]);
                    $player->getInventory()->addItem($item);

                    $player->sendPopup($this->message["key-received"]);


                    //Door locked succesfully
                    $event->setCancelled();
                    $player->sendMessage($this->message["locked-successfully"]);
                    $this->lock($event);
                }
                else{
                    $event->setCancelled();
                    unset($this->lockSession[$player->getName()]);
                    $player->sendMessage($this->message["block-already-locked"]);
                }

            }
            else if(isset($this->infoSession[$player->getName()])){
                $x = $event->getBlock()->getX();
                $y = $event->getBlock()->getY();
                $z = $event->getBlock()->getZ();

                $world = $player->getLevel()->getName();
                $name = $this->getLockedName($x, $y, $z, $world);
                unset($this->infoSession[$player->getName()]);
                $event->setCancelled();
                $event->getPlayer()->sendMessage("§3The name of the locked item is: §b$name");
            }
            else{
                if($player->hasPermission("lms.bypass")){
                    return;
                }elseif($this->isLockedDown($event->getBlock(), $event->getItem()) == "outdated"){
                    $player->sendMessage(str_replace("{keyname}", $event->getItem()->getName(), $this->message["outdated-key"]));
                    $event->setCancelled();
                }
                elseif($this->isLockedDown($event->getBlock(), $event->getItem())){
                    $event->setCancelled();
                    $player->sendPopup($this->message["block-locked"]);
                }
            }

        }
    }

    public function chestTouch(PlayerInteractEvent $event){
        if($event->getBlock()->getItemId() == ItemIds::CHEST){
            $tile =  $event->getPlayer()->getLevel()->getTile($event->getBlock());
            if($tile instanceof Chest){
                if($tile->isPaired()){
                    $chest = $tile->getPair();
                    $block = $event->getPlayer()->getLevel()->getBlock($chest);
                    if($block instanceof \pocketmine\block\Chest){
                        if($this->isLockedDown($block, $event->getItem())) {
                            $event->setCancelled();
                            $event->getPlayer()->sendPopup($this->message["block-locked"]);
                        }

                    }
                }
            }
        }
    }


    /**
     * @param PlayerInteractEvent $event
     */
    public function unlockTouch(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        if(isset($this->unlockSession[$player->getName()])){
            if($this->unlockSession[$player->getName()]){
                $event->setCancelled();
                $x = $event->getBlock()->getX();
                $y = $event->getBlock()->getY();
                $z = $event->getBlock()->getZ();
                $locked_id = $this->getLockedID($x, $y, $z, $event->getPlayer()->getLevel()->getName());
                $stmt = $this->handle->prepare("DELETE FROM doors WHERE door_id = :locked_id");
                $stmt->bindParam(":locked_id", $locked_id, SQLITE3_INTEGER);
                $stmt->execute();
                $stmt->close();
                $player->sendMessage($this->message["lock-removed"]);
                unset($this->unlockSession[$player->getName()]);
            }
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function doorBreak(BlockBreakEvent $event){
        $block = $event->getBlock();
        if($block instanceof Door || $block instanceof \pocketmine\block\Chest || $block instanceof Trapdoor){
                $door_status = $this->isLockedDown($event->getBlock(), $event->getItem());

                if($door_status !== null) {
                    if(!$door_status || $event->getPlayer()->hasPermission("lms.break")) {
                        $x = $block->getX();
                        $y = $block->getY();
                        $z = $block->getZ();
                        $locked_id = $this->getLockedID($x, $y, $z, $event->getPlayer()->getLevel()->getName());
                        $stmt = $this->handle->prepare("DELETE FROM doors WHERE door_id = :locked_id");
                        $stmt->bindParam(":locked_id", $locked_id, SQLITE3_INTEGER);
                        $stmt->execute();
                        $stmt->close();
                        $event->getPlayer()->sendMessage($this->message["lock-removed"]);
                    } else {
                        $event->setCancelled();
                        $event->getPlayer()->sendPopup($this->message["block-deny-break"]);
                    }
                }
            }
    }

    /**
     * @param $x
     * @param $y
     * @param $z
     * @param $worldname
     * @return mixed
     */
    public function getLockedName($x, $y, $z, $worldname){
        $door_id = $this->getLockedID($x, $y, $z, $worldname);
        $stmt = $this->handle->prepare("SELECT door_name FROM doors WHERE door_id = :door_id");
        $stmt->bindParam(":door_id", $door_id, SQLITE3_INTEGER);
        $result = $stmt->execute();

        while($row = $result->fetchArray()){
            return $row["door_name"];
            break;
        }
        $stmt->close();
    }


    /**
     * @param $name
     */
    public function unlock($name){
        $stmt = $this->handle->prepare("DELETE FROM doors WHERE door_name = :name");
        $stmt->bindParam(":name", $name, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
    }


    /**
     * @param Position $position
     * @param Item $item
     * @return bool|null
     */
    public function isLockedDown(Position $position, Item $item) : ?bool{
        $item_x = $position->getX();
        $item_y = $position->getY();
        $item_z = $position->getZ();

        $result = $this->handle->query("SELECT * FROM doors");
        $check = null;
        while($row = $result->fetchArray()){
            $row_loc = $row["location"];
            $loc_array = explode(",", $row_loc);
            $x = (int) $loc_array[0];
            $y = (int) $loc_array[1];
            $z = (int) $loc_array[2];
            if (($item_x == $x && $item_z == $z) && (abs($item_y - $y) <= 1 || abs($y - $item_y) <= 1) && $position->getLevel()->getName() == $row["world"]){


                    if($item->getCustomName() != $row["door_name"] || $item->getId() != $this->itemID || $item->getLore() != array("Key: ".$row["door_name"])){
                        //If the door exists in the database but it's locked, then "true" will be returned.
                        $check = true;
                        break;
                    } else if($item->getCustomName() != $row["door_name"] || $item->getId() != $this->itemID){
                        //Checks if user using old key.
                        $check = "outdated";
                        break;
                    }

                    else{
                        //If the door exists in the database and it's locked, but the user has the key then false will be returned.
                        $check = false;
                        break;
                    }
            }
            else{
                //NULL is returned if the door is not in the database
                $check = null;
            }
        }
        return $check;
    }


    /**
     * TODO: Update this method to use the Position class and not a random string
     * @param $event
     */
    public function lock($event){
        $player = $event->getPlayer();
        $item_x = $event->getBlock()->getX();
        $item_y = $event->getBlock()->getY();
        $item_z = $event->getBlock()->getZ();
        if($this->isLockedDown($event->getBlock(), $event->getItem()) == null){
            $location = "$item_x, $item_y, $item_z";
            $world = $event->getPlayer()->getLevel()->getName();
            $door_name = $this->lockSession[$player->getName()];
            $stmt = $this->handle->prepare("INSERT INTO DOORS (door_name, location, world) VALUES(:door_name, :location, :world)");
            $stmt->bindParam(":door_name", $door_name, SQLITE3_TEXT);
            $stmt->bindParam(":location", $location, SQLITE3_TEXT);
            $stmt->bindParam(":world", $world, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();
            unset($this->lockSession[$player->getName()]);
        }
    }


    /**
     * @return mixed
     */
    public function getAllDoors(){
        $result = $this->handle->query("SELECT * FROM doors");
        return $result;
    }

    /*
     * TODO: Update this method to require an instance of the Position class
     */
    public function getLockedID($x, $y, $z, $worldname){
        $result = $this->handle->query("SELECT * FROM doors");

        while($row = $result->fetchArray()) {
            $row_loc = $row["location"];
            $loc_array = explode(",", $row_loc);
            $x_j = (int) $loc_array[0];
            $y_j = (int) $loc_array[1];
            $z_j = (int) $loc_array[2];

            if($worldname == $row["world"]){
                if ($x == $x_j && $z == $z_j) {
                    if(abs($y - $y_j) <= 1 || abs($y_j - $y) <= 1) {
                        return $row["door_id"];
                        break;
                    }
                }
            }
        }
    }
}



