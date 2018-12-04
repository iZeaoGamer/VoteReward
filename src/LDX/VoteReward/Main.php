<?php

namespace LDX\VoteReward;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\item\Item;

class Main extends PluginBase {

  private $message = "";
  private $items = [];
  private $commands = [];
  private $debug = false;
  public $queue = [];

  public function onLoad() {
    if(file_exists($this->getDataFolder() . "config.yml")) {
      $c = $this->getConfig()->getAll();
      if(isset($c["API-Key"])) {
        if(trim($c["API-Key"]) != "") {
          if(!is_dir($this->getDataFolder() . "Lists/")) {
            mkdir($this->getDataFolder() . "Lists/");
          }
          file_put_contents($this->getDataFolder() . "Lists/minecraftpocket-servers.com.vrc", "{\"website\":\"http://minecraftpocket-servers.com/\",\"check\":\"http://minecraftpocket-servers.com/api-vrc/?object=votes&element=claim&key=" . $c["API-Key"] . "&username={USERNAME}\",\"claim\":\"http://minecraftpocket-servers.com/api-vrc/?action=post&object=votes&element=claim&key=" . $c["API-Key"] . "&username={USERNAME}\"}");
          rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.old.yml");
          $this->getLogger()->info("§eConverting API key to VRC file...");
        } else {
          rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.old.yml");
          $this->getLogger()->info("§eSetting up new configuration file...");
        }
      }
    }
  }

  public function onEnable() {
    $this->reload();
  }

  public function reload() {
    $this->saveDefaultConfig();
    if(!is_dir($this->getDataFolder() . "Lists/")) {
      mkdir($this->getDataFolder() . "Lists/");
    }
    $this->lists = [];
    foreach(scandir($this->getDataFolder() . "Lists/") as $file) {
      $ext = explode(".", $file);
      $ext = (count($ext) > 1 && isset($ext[count($ext) - 1]) ? strtolower($ext[count($ext) - 1]) : "");
      if($ext == "vrc") {
        $this->lists[] = json_decode(file_get_contents($this->getDataFolder() . "Lists/$file"),true);
      }
    }
    $this->reloadConfig();
    $config = $this->getConfig()->getAll();
    $this->message = $config["Message"];
    $this->items = [];
    foreach($config["Items"] as $i) {
      $r = explode(":", $i);
      $this->items[] = new Item($r[0], $r[1], $r[2]);
    }
    $this->commands = $config["Commands"];
    $this->debug = isset($config["Debug"]) && $config["Debug"] === true ? true : false;
  }

  public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
    switch(strtolower($command->getName())) {
      case "vote":
        if(isset($args[0]) && strtolower($args[0]) == "reload") {
          if(Utils::hasPermission($sender, "votereward.command.reload")) {
            $this->reload();
            $sender->sendMessage("§7[§aVote§2Reward§7] §dAll configurations have been reloaded.");
            break;
          }
          $sender->sendMessage("§cYou do not have permission to use this subcommand.");
          break;
        }
        if(!$sender instanceof Player) {
          $sender->sendMessage("This command must be used in-game.");
          break;
        }
        if(!Utils::hasPermission($sender, "votereward.command.vote")) {
          $sender->sendMessage("§cYou do not have permission to use this command.");
          break;
        }
        if(in_array(strtolower($sender->getName()), $this->queue)) {
          $sender->sendMessage("§7[§aVote§2Reward§7] §cSlow down! We're already checking lists for you.");
          break;
        }
        $this->queue[] = strtolower($sender->getName());
        $requests = [];
        foreach($this->lists as $list) {
          if(isset($list["check"]) && isset($list["claim"])) {
            $requests[] = new ServerListQuery($list["check"], $list["claim"]);
          }
        }
        $query = new RequestThread(strtolower($sender->getName()), $requests);
        $this->getScheduler()->scheduleAsyncTask($query);
        break;
      default:
        $sender->sendMessage("§cInvalid command.");
        break;
    }
    return true;
  }

  public function rewardPlayer($player, $multiplier) {
    if(!$player instanceof Player) {
      return true;
    }
    if($multiplier < 1) {
      $player->sendMessage("§7[§aVote§2Reward§7] §bYou haven't voted on any server lists! §aYou can vote for: §bin game Money ($10,000), §cReceive a Lotto ticket, §b2 Votepoints, §cCommands, §bRanks, §cand much more! §aVote for us to get these perks at the following links:\n§b1st: §3http://vmpevote.ml\n§b2nd: §3http://tinyurl.com/vmpevote2\n§b3rd: §bhttp://tinyurl.com/vmpevote3\n§aVote every day to be the top voter! \n§aAll three top voters will get Donor ranks! \n§aTop voter of the month will receive Top voter rank!");
      return true;
    }
    $clones = [];
    foreach($this->items as $item) {
      $clones[] = clone $item;
    }
    foreach($clones as $item) {
      $item->setCount($item->getCount() * $multiplier);
      $player->getInventory()->addItem($item);
    }
    foreach($this->commands as $command) {
      $this->getServer()->dispatchCommand(new ConsoleCommandSender, str_replace(array(
        "{USERNAME}",
        "{NICKNAME}",
        "{X}",
        "{Y}",
        "{Y1}",
        "{Z}"
      ), array(
        $player->getName(),
        $player->getDisplayName(),
        $player->getX(),
        $player->getY(),
        $player->getY() + 1,
        $player->getZ()
      ), Utils::translateColors($command)));
    }
    if(trim($this->message) != "") {
      $message = str_replace(array(
        "{USERNAME}",
        "{NICKNAME}"
      ), array(
        $player->getName(),
        $player->getDisplayName()
      ), Utils::translateColors($this->message));
      foreach($this->getServer()->getOnlinePlayers() as $p) {
        $p->sendMessage($message);
      }
      $this->getServer()->getLogger()->info($message);
    }
    $player->sendMessage("§7[§aVote§2Reward§7] §dYou voted on §5$multiplier §dserver list" . ($multiplier == 1 ? "" : "s") . "!");
  }

}
