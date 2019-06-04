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

class Main extends PluginBase implements Listener
{
    private $Items = array(ItemIds::IRON_DOOR, ItemIds::CHEST, ItemIds::IRON_TRAPDOOR);
    private $LockSession = array();
    private $path;
    private $unlockSession = array();


    public function onEnable(): void
    {
        $this->path = $this->getDataFolder() . "doors.json";
        $lockedJSON = new Config($this->getDataFolder() . "doors.json", Config::JSON, array());
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
                        $this->LockSession[$sender->getName()] = $args[0];
                        $sender->sendMessage("§cPlease touch the door you want to lock.");
                    } else {
                        $sender->sendMessage("§cMissing door-name! usage: ". $command->getUsage());

                    }

                    return true;

                case "unlock":
                    if(isset($args[0])){
                        $this->unlock($args[0]);
                        $sender->sendMessage("§aThe lock has been removed.!");
                    }
                    else{
                        $this->unlockSession[$sender->getName()] = true;
                        $sender->sendMessage("§aPlease touch the door you want to unlock.");

                    }
                    return true;

                case "makekey":
                    if (isset($args[0])) {
                        $item = ItemFactory::get(ItemIds::TRIPWIRE_HOOK);
                        $item->clearCustomName();
                        $item->setCustomName($args[0]);
                        $sender->getInventory()->setItemInHand($item);
                    } else {
                        $sender->sendMessage("§4Missing argument, please the name of the door that has to be locked. usage: " . $command->getUsage());
                    }
                    return true;
                default:
                    return false;
            }
        }

    }


    /**
     * @param BlockPlaceEvent $event
     */
    public function wirehook(BlockPlaceEvent $event){
        if($event->getBlock()->getItemId() == ItemIds::TRIPWIRE_HOOK){
            $event->setCancelled();
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function aanraking(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        if (in_array($event->getBlock()->getItemId(), $this->Items)){
            if (isset($this->LockSession[$player->getName()])){
                //sleutel in inventory plaatsen
                if($this->isLocked($event) === false){
                    $item = ItemFactory::get(ItemIds::TRIPWIRE_HOOK);
                    $item->clearCustomName();
                    $item->setCustomName($this->LockSession[$player->getName()]);
                    $player->getInventory()->addItem($item);
                    $player->sendPopup("§dYou received the key succesfully! Please check your inventory.");
                    //deur blijft closed
                    $event->setCancelled();
                    $player->sendMessage("§aThe door has been locked succesfully!");
                    $this->lock($event);
                }
                else{
                    $event->setCancelled();
                    unset($this->LockSession[$player->getName()]);
                    $player->sendMessage("§cThis door is already locked!");
                }

            }
            else{
                $key_name = $event->getItem()->getCustomName();
                if($this->isLocked($event, $key_name)){
                    $event->setCancelled();
                    $locked_name = $this->getLocked($event->getBlock()->getX(), $event->getBlock()->getY(), $event->getBlock()->getZ(), $event->getPlayer()->getLevel()->getName());
                    $player->sendPopup("§4The door §c$locked_name §4is locked.");
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
                $json_file = (array) json_decode(file_get_contents($this->path, true));
                $index = 0;
                foreach ($json_file as $value) {
                    $x_j = $value->coords->x;
                    $y_j = $value->coords->y;
                    $z_j = $value->coords->z;
                    if($player->getLevel()->getName() == $value->world){
                        if ($x == $x_j && $z == $z_j) {
                            if (abs($y - $y_j) <= 1 || abs($y_j - $y) <= 1) {
                                break;
                            }
                        }
                    }
                    $index += 1;
                }

                unset($json_file[$index]);
                $json_file = array_values($json_file);
                $new_json = json_encode($json_file, JSON_PRETTY_PRINT);
                file_put_contents($this->path, $new_json);
                $player->sendMessage("§aThe door has been unlocked!");
                unset($this->unlockSession[$player->getName()]);
            }
        }
    }

    public function breken(BlockBreakEvent $event){
        if(in_array($event->getBlock()->getItemId(), $this->Items)){
            if($this->isLocked($event)){
                $x = $event->getBlock()->getX();
                $y = $event->getBlock()->getY();
                $z = $event->getBlock()->getZ();
                $locked_name = $this->getLocked($x, $y, $z, $event->getPlayer()->getLevel()->getName());
                $this->unlock($locked_name);
                $event->getPlayer()->sendMessage("§aThe door has been unlocked!");
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
    public function getLocked($x, $y, $z, $worldname){
        $json_file = (array) json_decode(file_get_contents($this->path, true));
        foreach ($json_file as $value) {
            $x_j = $value->coords->x;
            $y_j = $value->coords->y;
            $z_j = $value->coords->z;
            $check = false;

            if($worldname == $value->world){
                if ($x == $x_j && $z == $z_j) {
                    if(abs($y - $y_j) <= 1 || abs($y_j - $y) <= 1) {
                        return $value->key_name;
                        break;
                    }
                }
            }

        }
    }


    /**
     * @param $name
     */
    public function unlock($name){
        $json_file = (array) json_decode(file_get_contents($this->path, true));
        $index = 0;
        foreach($json_file as $value){
            if($value->key_name == $name){
                break;
            }
            $index++;
        }
        unset($json_file[$index]);
        file_put_contents($this->path, json_encode(array_values($json_file), JSON_PRETTY_PRINT));
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

        $json_file = json_decode(file_get_contents($this->path, true));

        $check = array();
        if($this->isJSONempty($this->path) === true){
            $check = false;
        }
        else{
            foreach ($json_file as $value) {
                $x = $value->coords->x;
                $y = $value->coords->y;
                $z = $value->coords->z;
                $check = false;

                if($event->getPlayer()->getLevel()->getName() == $value->world){
                    if ($item_x == $x && $item_z == $z) {
                        if (abs($item_y - $y) <= 1 || abs($y - $item_y) <= 1) {
                            if (isset($value->key_name)) {
                                if($key_name != $value->key_name){
                                    $check = true;
                                    break;
                                }
                            }
                            else{
                                $check = true;
                            }
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
            $position = array(array("key_name" => $this->LockSession[$player->getName()],"world" => $player->getLevel()->getName(), "coords" => array("x" => $item_x, "y" => $item_y, "z" => $item_z)));
            if ($this->isJSONempty($this->path) === false) {
                $old_json = (array)json_decode(file_get_contents($this->path, true));
                $position_js = array_merge($old_json, $position);
                $new_json = json_encode($position_js, JSON_PRETTY_PRINT);
                file_put_contents($this->path, $new_json);
            }
            else {
                $position_json = json_encode($position, JSON_PRETTY_PRINT);
                file_put_contents($this->path, $position_json);
            }
            unset($this->LockSession[$player->getName()]);
        }
    }

    /**
     * @param $path
     * @return bool
     */
    public function isJSONempty($path) : bool{
        if (json_decode(file_get_contents($path, true)) === null || file_get_contents($path, true) == "[]") {
            return true;
        }
        else{
            return false;
        }
    }
}



