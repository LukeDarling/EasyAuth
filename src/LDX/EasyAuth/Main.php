<?php

namespace LDX\EasyAuth;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerItemConsumeEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;

class Main extends PluginBase implements Listener {

  private $data;
  private $players;
  private $failed;

  public function onEnable() {
    $this->checkFolders();
    $this->data = array();
    $this->players = array();
    $this->failed = array();
    foreach($this->getServer()->getOnlinePlayers() as $player) {
      $this->join($player);
    }
    $this->getServer()->getPluginManager()->registerEvents($this,$this);
  }

  public function sendMessage($player,$message) {
    $player->sendMessage("§f[§eEasy§aAuth§f] $message");
  }

  public function checkFolders() {
    if(!is_dir($this->getDataFolder() . "players/")) {
      if(!is_dir($this->getDataFolder())) {
        mkdir($this->getDataFolder());
      }
      mkdir($this->getDataFolder() . "players/");
    }
  }

  public function getPlayer($player) {
    if(!$this->isFileCreated($player)) {
      file_put_contents($this->getPlayerFile($player),"{\"username\":\"" . strtolower($player->getName()) . "\",\"registered\":false,\"jointime\":" . time() . "}");
    }
    if(!isset($this->data[strtolower($player->getName())])) {
      $this->data[strtolower($player->getName())] = json_decode(file_get_contents($this->getPlayerFile($player)),true);
    }
    return $this->data[strtolower($player->getName())];
  }

  public function setPlayer($player,$data) {
    $this->data[strtolower($player->getName())] = $data;
    return file_put_contents($this->getPlayerFile($player),json_encode($data));
  }

  public function getPlayerFile($player) {
    $this->checkFolders();
    $f = $this->getDataFolder() . "players";
    $a = substr(strtolower($player->getName()),0,1);
    $ab = substr(strtolower($player->getName()),0,2);
    $n = strtolower($player->getName());
    if(!is_dir("$f/$a/$ab/")) {
      if(!is_dir("$f/$a/")) {
        mkdir("$f/$a/");
      }
      mkdir("$f/$a/$ab/");
    }
    return "$f/$a/$ab/$n.json";
  }

  public function isFileCreated($player) {
    return file_exists($this->getPlayerFile($player));
  }

  public function isRegistered($player) {
    $data = $this->getPlayer($player);
    return $data["registered"];
  }

  public function canAutoLogin($player) {
    if($this->isRegistered($player)) {
      $file = $this->getPlayer($player);
      if($player->getAddress() == $file["ip"]) {
        return true;
      }
    }
    return false;
  }

  public function setAuthed($player,$value) {
    $this->players[strtolower($player->getName())] = $value;
  }

  public function isAuthed($player) {
    return (isset($this->players[strtolower($player->getName())]) ? $this->players[strtolower($player->getName())] : false);
  }

  public function fail($player) {
    $ip = $player->getAddress();
    if(!isset($this->failed[$ip])) {
      $this->failed[$ip] = 0;
    }
    $this->failed[$ip]++;
    if($this->failed[$ip] >= 5) {
      $player->close($player->getName() . " has left the game.","§r§fYou have been kicked by §eEasy§aAuth§f for attempting to hack " . $player->getName() . "'s account.");
      return;
    }
    if($this->failed[$ip] >= 3) {
      $this->sendMessage($player,"§6Keep this up, and you'll be kicked.");
    }
  }

  public function join($player) {
    if(!$this->isRegistered($player)) {
      $this->setAuthed($player,true);
      $this->sendMessage($player,"§cYou are not registered.");
    } else if($this->canAutoLogin($player)) {
      $this->setAuthed($player,true);
      $this->sendMessage($player,"§aYou have been authenticated.");
    } else {
      $this->setAuthed($player,false);
      $this->sendMessage($player,"§cPlease login. /login <password>");print 3;
    }
  }

  public function login($player,$password) {
    if(!$this->isRegistered($player)) {
      $this->sendMessage($player,"§cYou are not registered.");
      return;
    }
    if($this->isAuthed($player)) {
      $this->sendMessage($player,"§cYou are already logged in.");
      return;
    }
    $file = $this->getPlayer($player);
    if($file["hash"] == $this->hash($player,$password)) {
      $file["ip"] = $player->getAddress();
      $this->setPlayer($player,$file);
      $this->setAuthed($player,true);
      $this->failed[$player->getAddress()] = 0;
      $this->sendMessage($player,"§aYou have been authenticated.");
      return;
    }
    $this->sendMessage($player,"§cIncorrect password.");
    $this->fail($player);
  }

  public function register($player,$password) {
    if($this->isRegistered($player)) {
      $this->sendMessage($player,"§cYou are already registered.");
      return;
    }
    if(strlen($password) < 4) {
      $this->sendMessage($player,"§cPassword too short.");
      return;
    }
    if(strlen($password) > 64) {
      $this->sendMessage($player,"§cPassword too long.");
      return;
    }
    $old = $this->getPlayer($player);
    $this->setPlayer($player,array("username" => $old["username"],"registered" => true,"jointime" => $old["jointime"],"registertime" => time(),"ip" => $player->getAddress(),"hash" => $this->hash($player,$password)));
    $this->sendMessage($player,"§aYou have been registered.");
  }

  public function hash($player,$password) {
    $username = $player->getName();
    return strtoupper(hash("whirlpool",hash("gost",strtoupper($username) . "EasyAuth" . $password) . hash("gost",$password . "LDX" . strtolower($username))));
  }

  public function onPlayerPreLogin(PlayerPreLoginEvent $event) {
    if($this->isRegistered($event->getPlayer())) {
      if($this->getServer()->getPlayerExact($event->getPlayer()->getName()) instanceof Player) {
        if($this->isAuthed($this->getServer()->getPlayerExact($event->getPlayer()->getName()))) {
          $event->setCancelled();
          $event->setKickMessage("§cThe player " . $event->getPlayer()->getName() . " is already connected.");
        } else {
          if(!$this->canAutoLogin($event->getPlayer())) {
            $event->setCancelled();
            $event->setKickMessage("§cThe player " . $event->getPlayer()->getName() . " is already connected.");
          }
        }
      }
    }
  }

  public function onPlayerJoin(PlayerJoinEvent $event) {
    $this->join($event->getPlayer());
  }

  public function onPlayerMove(PlayerMoveEvent $event) {
    if(!$this->isAuthed($event->getPlayer())) {
      $event->setCancelled();
    }
  }

  public function onPlayerInteract(PlayerInteractEvent $event) {
    if(!$this->isAuthed($event->getPlayer())) {
      $event->setCancelled();
    }
  }

  public function onPlayerDropItem(PlayerDropItemEvent $event) {
    if(!$this->isAuthed($event->getPlayer())) {
      $event->setCancelled();
    }
  }

  public function onPlayerItemConsume(PlayerItemConsumeEvent $event) {
    if(!$this->isAuthed($event->getPlayer())) {
      $event->setCancelled();
    }
  }

  public function onPlayerQuit(PlayerQuitEvent $event) {
    $this->setAuthed($event->getPlayer(),false);
  }

  public function onEntityRegainHealth(EntityRegainHealthEvent $event) {
    if(($player = $event->getEntity()) instanceof Player) {
      if(!$this->isAuthed($player)) {
        $event->setCancelled();
      }
    }
  }

  public function onEntityShootBow(EntityShootBowEvent $event) {
    if(($player = $event->getEntity()) instanceof Player) {
      if(!$this->isAuthed($player)) {
        $event->setCancelled();
      }
    }
  }

  public function onEntityDamage(EntityDamageEvent $event) {
    if($event instanceof EntityDamageByEntityEvent) {
      if(($attacker = $event->getDamager()) instanceof Player) {
        if(!$this->isAuthed($attacker)) {
          $event->setCancelled();
        }
      }
    }
    if(($entity = $event->getEntity()) instanceof Player) {
      if(!$this->isAuthed($entity)) {
        $event->setCancelled();
      }
    }
  }

  public function onBlockPlace(BlockPlaceEvent $event) {
    if(!$this->isAuthed($event->getPlayer())) {
      $event->setCancelled();
    }
  }

  public function onBlockBreak(BlockBreakEvent $event) {
    if(!$this->isAuthed($event->getPlayer())) {
      $event->setCancelled();
    }
  }

  public function onPlayerCommandPreprocess(PlayerCommandPreprocessEvent $event) {
    $player = $event->getPlayer();
    $command = explode(" ",$event->getMessage());
    switch(strtolower(array_shift($command))) {
      case "/login":
        $this->login($player,($password = array_shift($command)) === null ? "" : $password);
        $event->setCancelled();
        break;
      case "/register":
        $this->register($player,($password = array_shift($command)) === null ? "" : $password);
        $event->setCancelled();
        break;
      default:
        if(!$this->isAuthed($player)) {
          $this->sendMessage($player,"§cPlease login. /login <password>");print 4;
          $event->setCancelled();
        }
    }
  }

}