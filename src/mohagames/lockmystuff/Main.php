<?php

namespace mohagames\lockmystuff;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
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
    private $Items = array(ItemIds::IRON_DOOR, ItemIds::CHEST, ItemIds::IRON_TRAPDOOR);
    private $lockSession = array();
    private $handle;
    private $unlockSession = array();
    private $config;
    private $itemID;
    private $infoSession = array();

    public function onEnable(): void
    {
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, array("key-item" => ItemIds::TRIPWIRE_HOOK));
        $this->itemID = $this->config->get("key-item");
        $this->handle = new SQLite3($this->getDataFolder() . "doors.db");
        $this->handle->query("CREATE TABLE IF NOT EXISTS doors(door_id INTEGER PRIMARY KEY AUTOINCREMENT,door_name TEXT,location TEXT, world TEXT)");
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /**
     * @param CommandSender $sender
     * @param Command $command
     * @param string $label
     * @param array $args
     * @return bool
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if($sender instanceof Player){
            switch ($command->getName()) {
                case "lock":
                    if (isset($args[0])) {
                        $this->lockSession[$sender->getName()] = $args[0];
                        $sender->sendMessage("§cPlease touch the item you want to lock.");
                    } else {
                        $sender->sendMessage("§cMissing item-name! usage: ". $command->getUsage());

                    }

                    return true;

                case "unlock":
                    if(isset($args[0])){
                        $this->unlock($args[0]);
                        $sender->sendMessage("§aThe lock has been removed!");
                    }
                    else{
                        $this->unlockSession[$sender->getName()] = true;
                        $sender->sendMessage("§aPlease touch the item you want to unlock.");

                    }
                    return true;

                case "makekey":
                    if (isset($args[0])) {
                        $item = ItemFactory::get($this->itemID);
                        $item->clearCustomName();
                        $item->setCustomName($args[0]);
                        $sender->getInventory()->addItem($item);
                    } else {
                        $sender->sendMessage("§4Missing argument, please specify the name of the item that has to be locked. usage: " . $command->getUsage());
                    }
                    return true;

                case "lockedinfo":
                    $this->infoSession[$sender->getName()] = true;
                    $sender->sendMessage("§aPlease touch the item you want to info about.");
                    return true;

                default:
                    return false;
            }
        }
        else{
            $this->getLogger()->info("Please execute this command ingame");
        }

    }


    /**
     * @param BlockPlaceEvent $event
     */
    public function wirehook(BlockPlaceEvent $event){
        if($event->getBlock()->getItemId() == $this->itemID){
            $event->setCancelled();
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function aanraking(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        if (in_array($event->getBlock()->getItemId(), $this->Items)){
            if (isset($this->lockSession[$player->getName()])){
                //sleutel in inventory plaatsen
                if($this->isLocked($event) === false){
                    $item = ItemFactory::get($this->itemID);
                    $item->clearCustomName();
                    $item->setCustomName($this->lockSession[$player->getName()]);
                    $player->getInventory()->addItem($item);
                    $player->sendPopup("§dYou received the key succesfully! Please check your inventory.");
                    //deur blijft closed
                    $event->setCancelled();
                    $player->sendMessage("§aThe door has been locked succesfully!");
                    $this->lock($event);
                }
                else{
                    $event->setCancelled();
                    unset($this->lockSession[$player->getName()]);
                    $player->sendMessage("§cThis door is already locked!");
                }

            }
            elseif(isset($this->infoSession[$player->getName()])){
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
                $key_name = $event->getItem()->getCustomName();
                if($this->isLocked($event, $key_name)){
                    $event->setCancelled();
                    $player->sendPopup("§4The door is locked.");
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
                $player->sendMessage("§aThe door has been unlocked!");
                unset($this->unlockSession[$player->getName()]);
            }
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function breken(BlockBreakEvent $event){
        if(in_array($event->getBlock()->getItemId(), $this->Items)){
            if($this->isLocked($event)){
                $key_name = $event->getItem()->getCustomName();
                if((!$this->isLocked($event, $key_name) && $event->getItem()->getId() == $this->itemID) || $event->getPlayer()->hasPermission("lms.break")){
                    $x = $event->getBlock()->getX();
                    $y = $event->getBlock()->getY();
                    $z = $event->getBlock()->getZ();
                    $locked_id = $this->getLockedID($x, $y, $z, $event->getPlayer()->getLevel()->getName());
                    $stmt = $this->handle->prepare("DELETE FROM doors WHERE door_id = :locked_id");
                    $stmt->bindParam(":locked_id", $locked_id, SQLITE3_INTEGER);
                    $stmt->execute();
                    $stmt->close();
                    $event->getPlayer()->sendMessage("§aThe door has been unlocked!");
                }
                else{
                    $event->setCancelled();
                    $event->getPlayer()->sendPopup("§4You cannot break this door!");
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
     * @param $event
     * @param $key_name
     * @return array|bool
     */
    public function isLocked($event, $key_name = null){
        $item_x = $event->getBlock()->getX();
        $item_y = $event->getBlock()->getY();
        $item_z = $event->getBlock()->getZ();

        $result = $this->handle->query("SELECT * FROM doors");
        $check = false;
        while($row = $result->fetchArray()){
            $row_loc = $row["location"];
            $loc_array = explode(",", $row_loc);
            $x = (int) $loc_array[0];
            $y = (int) $loc_array[1];
            $z = (int) $loc_array[2];
            if($event->getPlayer()->getLevel()->getName() == $row["world"]){
                if ($item_x == $x && $item_z == $z) {
                    if (abs($item_y - $y) <= 1 || abs($y - $item_y) <= 1) {
                            if($key_name != $row["door_name"] || $event->getItem()->getId() != $this->itemID){
                                $check = true;
                                break;
                            }
                    }
                }
            }
        }
        return $check;
    }





    /**
     * @param $event
     */
    public function lock($event){
        $player = $event->getPlayer();
        $item_x = $event->getBlock()->getX();
        $item_y = $event->getBlock()->getY();
        $item_z = $event->getBlock()->getZ();
        if(!$this->isLocked($event)){
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



