<?php 

namespace onebone\minecombat;

use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\level\Position;
use pocketmine\level\Level;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\item\Item;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\scheduler\AsyncTask;

use onebone\minecombat\gun\Pistol;
use onebone\minecombat\grenade\FragmentationGrenade;
use onebone\minecombat\task\GameStartTask;
use onebone\minecombat\task\GameEndTask;
use onebone\minecombat\task\TeleportTask;
use onebone\minecombat\task\PopupTask;

class MineCombat extends PluginBase implements Listener{
	const STAT_GAME_END = 0;
	const STAT_GAME_PREPARE = 1;
	const STAT_GAME_IN_PROGRESS = 2;
	
	const PLAYER_DEAD = 0;
	const PLAYER_ALIVE = 1;
	
	const TEAM_RED = 0;
	const TEAM_BLUE = 1;
	
	const GRENADE_ID = 341;
	const GUN_ID = 105;
	
	private $rank, $team, $players, $score, $status, $spawnPos = null, $nextLevel = null, $threads, $level, $killDeath;
	
	private static $obj;

	/**
	 * @return MineCombat
	 */
	public static function getInstance(){
		return self::$obj;
	}
	
	public function prepareGame(){
		$this->status = self::STAT_GAME_PREPARE;
		
		$this->getServer()->getScheduler()->scheduleDelayedTask(new GameStartTask($this), $this->getConfig()->get("prepare-time") * 20);
		
		$this->getServer()->broadcastMessage(TextFormat::AQUA."[MineCombat] Preparation time is started.");
		
		$pos = $this->getConfig()->get("spawn-pos");
		
		if($pos === []) return;
		$randKey = array_rand($pos);
		
		$randPos = $pos[$randKey];
		
		if(($level = $this->getServer()->getLevelByName($randPos["blue"][3])) instanceof Level){
			$this->spawnPos = [new Position($randPos["red"][0], $randPos["red"][1], $randPos["red"][2], $level), new Position($randPos["blue"][0], $randPos["blue"][1], $randPos["blue"][2], $level)];
			$this->nextLevel = $randKey;
		}else{
			$this->getLogger()->critical("Invalid level name was given.");
			$this->getServer()->shutdown();
		}
	}
	
	public function startGame(){
		if(count($this->getServer()->getOnlinePlayers()) < 1){ ///// TODO: CHANGE HERE ON RELEASE
			$this->getServer()->broadcastMessage(TextFormat::YELLOW."Player is not enough to start the match. Preparation time is going longer...");
			$this->getServer()->getScheduler()->scheduleDelayedTask(new GameStartTask($this), $this->getConfig()->get("prepare-time") * 20);
			return;
		}
		
		$blue = $red = 0;
		
		$this->status = self::STAT_GAME_IN_PROGRESS;
		
		$online = $this->getServer()->getOnlinePlayers();
		shuffle($online);
		foreach($online as $player){
			if($blue < $red){
				$this->players[$player->getName()][2] = self::TEAM_BLUE;
				
				if(isset($this->level[$player->getName()])){
					$level = floor(($this->level[$player->getName()] / 10000));
					$player->setNameTag("Lv.".$level.TextFormat::BLUE.$player->getName());
				}else{
					$player->setNameTag(TextFormat::BLUE.$player->getName());
				}
				
				$player->sendMessage("[MineCombat] You are ".TextFormat::BLUE."BLUE".TextFormat::RESET." team.");
				if(isset($this->players[$player->getName()][0])){
					$this->players[$player->getName()][0]->setColor([40, 45, 208]);
				}
				
				++$blue;
			}else{
				$this->players[$player->getName()][2] = self::TEAM_RED;
				
				if(isset($this->level[$player->getName()])){
					$level = floor(($this->level[$player->getName()] / 10000));
					$player->setNameTag("Lv.".$level.TextFormat::RED.$player->getName());
				}else{
					$player->setNameTag(TextFormat::RED.$player->getName());
				}
				
				$player->sendMessage("[MineCombat] You are ".TextFormat::RED."RED".TextFormat::RESET." team.");
				if(isset($this->players[$player->getName()][0])){
					$this->players[$player->getName()][0]->setColor([247, 2, 9]);
				}
				
				++$red;
			}
			$this->teleportToSpawn($player);
			$player->setHealth(20);
			
			if(!$player->getInventory()->contains(Item::get(self::GRENADE_ID))){
				$player->getInventory()->addItem(Item::get(self::GRENADE_ID, 0, 2));
			}
			
			if(!$player->getInventory()->contains(Item::get(self::GUN_ID))){
				$player->getInventory()->addItem(Item::get(self::GUN_ID));
			}
			if(isset($this->players[$player->getName()][0])){
				$this->players[$player->getName()][0]->setAmmo(50);
			}
			$this->players[$player->getName()][3] = time();
		}
		$this->score = [0, 0];
		
		$this->getServer()->getScheduler()->scheduleDelayedTask(new GameEndTask($this), $this->getConfig()->get("game-time") * 20);
		
		$this->getServer()->broadcastMessage(TextFormat::GREEN."[MineCombat] Game is started. Kill as much as enemies and get more scores.");
	}
	
	public function endGame(){
		$this->status = self::STAT_GAME_END;
		
		$winner = TextFormat::YELLOW."TIED".TextFormat::RESET;
		if($this->score[self::TEAM_RED] > $this->score[self::TEAM_BLUE]){
			$winner = TextFormat::RED."RED".TextFormat::RESET." team win";
		}elseif($this->score[self::TEAM_BLUE] > $this->score[self::TEAM_RED]){
			$winner = TextFormat::BLUE."BLUE".TextFormat::RESET." team win";
		}
		
		foreach($this->getServer()->getOnlinePlayers() as $player){
			$player->setNameTag($player->getName());
		}
		$this->getServer()->broadcastMessage(TextFormat::GREEN."[MineCombat] Game has been finished. ".$winner);
		
		$this->prepareGame();
	}
	
	public function isEnemy($player1, $player2){
		if(isset($this->players[$player1]) and isset($this->players[$player2])){
			return ($this->players[$player1][2] !== $this->players[$player2][2]);
		}
		return false;
	}

	/**
	 * @param Player|string $player
	 *
	 * @return Pistol|null
	 */
	public function getGunByPlayer($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		
		if(isset($this->players[$player][0])){
			return $this->players[$player][0];
		}
		return null;
	}
	
	public function broadcastPopup($message){
		foreach($this->getServer()->getOnlinePlayers() as $player){
			$player->sendTip($message);
		}
	}
	
	public function getPlayersCountOnTeam($team){
		$ret = 0;
		foreach($this->players as $stats){
			if($stats[2] === $team){
				$ret++;
			}
		}
		return $ret;
	}
	
	public function teleportToSpawn(Player $player){
		if($this->spawnPos === null) return;
		$team = $this->players[$player->getName()][2];
		switch($team){
			case self::TEAM_BLUE:
			$player->teleport($this->spawnPos[1]);
			break;
			default: // RED team or not decided
			$player->teleport($this->spawnPos[0]);
			break;
		}
	}
	
	public function showPopup(){
		if($this->status === self::STAT_GAME_IN_PROGRESS){
			$now = time();

			foreach($this->getServer()->getOnlinePlayers() as $player){
				if(!isset($this->players[$player->getName()])) continue;
				$msg = "";
				if($this->players[$player->getName()][2] === self::TEAM_RED){
					$popup = TextFormat::RED."RED TEAM\n".TextFormat::WHITE."Scores: ".TextFormat::RED.($this->score[self::TEAM_RED]).TextFormat::WHITE." / ".TextFormat::BLUE.($this->score[self::TEAM_BLUE].TextFormat::WHITE." / xp : ".TextFormat::YELLOW.$this->level[$player->getName()]);
				}else{
					$popup = (TextFormat::BLUE."BLUE TEAM\n".TextFormat::WHITE."Scores: ".TextFormat::BLUE.$this->score[self::TEAM_BLUE].TextFormat::WHITE." / ".TextFormat::RED.$this->score[self::TEAM_RED].TextFormat::WHITE." / xp : ".TextFormat::YELLOW.$this->level[$player->getName()]);
				}
				$ammo = "";
				if(isset($this->players[$player->getName()][0])){
					$ammo = $this->players[$player->getName()][0]->getLeftAmmo();
					if($ammo <= 0){
						$ammo = TextFormat::RED.$ammo;
					}
				}
				$popup .= TextFormat::WHITE."\nAmmo: ".$ammo;
				$player->sendPopup($popup);
			}
		}else{
			foreach($this->getServer()->getOnlinePlayers() as $player){
				$levelStr = "";
				if($this->nextLevel !== null){
					$levelStr = "\nNext map: ".TextFormat::AQUA.$this->nextLevel;
				}
				$player->sendPopup(TextFormat::GREEN."Preparation in progress".$levelStr);
			}
		}
	}
	
	public function submitAsyncTask(AsyncTask $task){
		$this->getServer()->getScheduler()->scheduleAsyncTask($task);
	}
	
	public function onEnable(){
		self::$obj = $this;
		
		if(!file_exists($this->getDataFolder())){
			mkdir($this->getDataFolder());
		}
		if(!is_file($this->getDataFolder()."rank.dat")){
			file_put_contents($this->getDataFolder()."rank.dat", serialize([]));
		}
		$this->rank = unserialize(file_get_contents($this->getDataFolder()."rank.dat"));
		
		if(!is_file($this->getDataFolder()."level.dat")){
			file_put_contents($this->getDataFolder()."level.dat", serialize([]));
		}
		$this->level = unserialize(file_get_contents($this->getDataFolder()."level.dat"));
		
		if(!is_file($this->getDataFolder()."kill_death.dat")){
			file_put_contents($this->getDataFolder()."kill_death.dat", serialize([]));
		}
		$this->killDeath = unserialize(file_get_contents($this->getDataFolder()."kill_death.dat"));
		
		$this->players = [];
		
		$this->saveDefaultConfig();
		
		$spawnPos = $this->getConfig()->get("spawn-pos");
		
		foreach($spawnPos as $key => $data){
			if(!isset($data["blue"]) or !isset($data["red"])){
				unset($spawnPos[$key]);
			}
		}
		if($spawnPos !== [] and $spawnPos !== null){ // TODO: Fix here
			$this->prepareGame();
		}else{
			$this->getLogger()->warning("Set the spawn position of each team by /spawnpos and restart server to start the match.");
			return;
		}
		
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new PopupTask($this), 10);
	}
	
	public function onDisable(){
		file_put_contents($this->getDataFolder()."rank.dat", serialize($this->rank));
		file_put_contents($this->getDataFolder()."level.dat", serialize($this->level));
		file_put_contents($this->getDataFolder()."kill_death.dat", serialize($this->killDeath));
	}
	
	public function onCommand(CommandSender $sender, Command $command, $label, array $params){
		if(!($sender instanceof Player)){
			return true;
		}

		switch($command->getName()){
			case "rank":
			$data = $this->killDeath[0];
			
			arsort($data);
			
			$cnt = 0;
			$send = "Your status : ".TextFormat::YELLOW.$this->killDeath[0][$sender->getName()].TextFormat::WHITE."kills/".TextFormat::YELLOW.$this->killDeath[1][$sender->getName()].TextFormat::WHITE."deaths\n--------------------\n";
			foreach($data as $player => $datam){
				$send .= TextFormat::GREEN.$player.TextFormat::WHITE." ".TextFormat::YELLOW.$datam.TextFormat::WHITE."kills/".TextFormat::YELLOW.$this->killDeath[1][$player].TextFormat::WHITE."deaths\n";
				if($cnt >= 5){
					break;
				}
				++$cnt;
			}
			$sender->sendMessage($send);
			return true;
			case "spawnpos":
			$sub = strtolower(array_shift($params));
			switch($sub){
				case "blue":
				case "b":
				$name = array_shift($params);
				if(trim($name) === ""){
					$sender->sendMessage(TextFormat::RED."Usage: /spawnpos blue <name>");
					return true;
				}
				
				$config = $this->getConfig()->get("spawn-pos");
				if(isset($config[$name]["blue"])){
					$sender->sendMessage(TextFormat::RED."$name already exists.");
					return;
				}
				$loc = [
					$sender->getX(), $sender->getY(), $sender->getZ(), $sender->getLevel()->getFolderName()
				];
				$config[$name]["blue"] = $loc;
				$this->getConfig()->set("spawn-pos", $config);
				$this->getConfig()->save();
				$sender->sendMessage("[MineCombat] Spawn position of BLUE team set.");
				return true;
				case "r":
				case "red":
				
				$name = array_shift($params);
				if(trim($name) === ""){
					$sender->sendMessage(TextFormat::RED."Usage: /spawnpos red <name>");
					return true;
				}
				
				$config = $this->getConfig()->get("spawn-pos");
				if(isset($config[$name]["red"])){
					$sender->sendMessage(TextFormat::RED."$name already exists.");
					return;
				}
				
				$loc = [
					$sender->getX(), $sender->getY(), $sender->getZ(), $sender->getLevel()->getFolderName()
				];
				$config[$name]["red"] = $loc;
				$this->getConfig()->set("spawn-pos", $config);
				$this->getConfig()->save();
				$sender->sendMessage("[MineCombat] Spawn position of RED team set.");
				return true;
				case "remove":
				$name = array_shift($params);
				if(trim($name) === ""){
					$sender->sendMessage(TextFormat::RED."Usage: /spawnpos blue <name>");
					return true;
				}
				
				$config = $this->getConfig()->get("spawn-pos");
				$config[$name] = null;
				unset($config[$name]);
				
				$this->getConfig()->set("spawn-pos", $config);
				$this->getConfig()->save();
				return true;
				case "list":
				$list = implode(", ", array_keys($this->getConfig()->get("spawn-pos")));
				$sender->sendMessage("Positions list: \n".$list);
				return true;
				default:
				$sender->sendMessage("Usage: ".$command->getUsage());
			}
			return true;
		}

		return true;
	}
	
	public function onInteract(PlayerInteractEvent $event){
		if($this->status === self::STAT_GAME_IN_PROGRESS){
			$player = $event->getPlayer();
			$item = $player->getInventory()->getItemInHand();
			if($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){
				if($item->getId() === self::GUN_ID){
					$this->players[$player->getName()][0]->shoot();
				}
			}elseif($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_AIR){
				if($item->getId() === self::GRENADE_ID){
					$this->players[$player->getName()][1]->lob($event->getTouchVector());
					$player->getInventory()->removeItem(Item::get(self::GRENADE_ID, 0, 1));
				}
			}
		}
	}
	
	public function onLoginEvent(PlayerLoginEvent $event){
		$player = $event->getPlayer();
		
		if(!isset($this->level[$player->getName()])){
			$this->level[$player->getName()] = 0;
		}
		if(!isset($this->killDeath[$player->getName()])){
			$this->killDeath[0][$player->getName()] = 0;
			$this->killDeath[1][$player->getName()] = 0;
		}
		
		$this->players[$player->getName()] = [
			new Pistol($this, $player, 50),
			new FragmentationGrenade($this, $player),
			-1,
			time()
		];
	}
	
	public function onJoinEvent(PlayerJoinEvent $event){
		$player = $event->getPlayer();
		
		if(!$player->getInventory()->contains(Item::get(self::GUN_ID))){
			$player->getInventory()->addItem(Item::get(self::GUN_ID));
		}
		
		if(!$player->getInventory()->contains(Item::get(self::GRENADE_ID))){
			$player->getInventory()->addItem(Item::get(self::GRENADE_ID, 2));
		}
		
		if($this->status === self::STAT_GAME_IN_PROGRESS){
			$redTeam = $this->getPlayersCountOnTeam(self::TEAM_RED);
			$blueTeam = $this->getPlayersCountOnTeam(self::TEAM_BLUE);
			if($redTeam > $blueTeam){
				$team = self::TEAM_BLUE;
				
				$level = floor(($this->level[$player->getName()] / 10000));
				$player->setNameTag("Lv.".$level.TextFormat::BLUE.$player->getName());
				
				$this->players[$player->getName()][0]->setColor([40, 45, 208]);
			}else{
				$team = self::TEAM_RED;
				
				$level = floor(($this->level[$player->getName()] / 10000));
				$player->setNameTag("Lv.".$level.TextFormat::RED.$player->getName());
				
				$this->players[$player->getName()][0]->setColor([247, 2, 9]);
			}
			$this->players[$player->getName()][2] = $team;
			
			$this->teleportToSpawn($player);
			$player->sendMessage("[MineCombat] You are ".($team === self::TEAM_RED ? TextFormat::RED."RED" : TextFormat::BLUE."BLUE").TextFormat::WHITE." team. Kill as much as enemies and get more scores.");
		}else{
			$player->sendMessage("[MineCombat] It is preparation time. Please wait for a while to start the match.");
		}
	}
	
	public function onQuitEvent(PlayerQuitEvent $event){
		$player = $event->getPlayer();
		
		if(isset($this->players[$player->getName()])){
			unset($this->players[$player->getName()]);
		}
	}
	
	public function onDeath(PlayerDeathEvent $event){
		$player = $event->getEntity();
		
		if($this->status === self::STAT_GAME_IN_PROGRESS){			
			$cause = $player->getLastDamageCause();
			if(!($cause instanceof EntityDamageByEntityEvent)){
				return;
			}

			if($cause !== null and $cause->getCause() === 15){
				$damager = $cause->getDamager();
				if($damager instanceof Player){
					if($this->players[$damager->getName()][2] === self::TEAM_BLUE){
						$damagerColor = TextFormat::BLUE;
						$playerColor = TextFormat::RED;
						$this->score[self::TEAM_BLUE]++;
					}else{
						$damagerColor = TextFormat::RED;
						$playerColor = TextFormat::BLUE;
						$this->score[self::TEAM_RED]++;
					}
					$firstKill = "";
					if($this->score[self::TEAM_BLUE] + $this->score[self::TEAM_RED] <= 1){
						$firstKill = TextFormat::YELLOW."FIRST BLOOD\n".TextFormat::WHITE;
					}
					$this->broadcastPopup($firstKill.$damagerColor.$damager->getName().TextFormat::WHITE." -> ".$playerColor.$player->getName());
					
					++$this->killDeath[0][$damager->getName()];
					++$this->killDeath[1][$player->getName()];
					
					$this->level[$damager->getName()] += ($damager->getHealth() * 5);
					$level = floor(($this->level[$damager->getName()] / 10000));
					$damager->setNameTag("Lv.".$level.$damagerColor.$damager->getName());
				}
			}elseif($cause !== null and $cause->getCause() === 16){
				$damager = $cause->getDamager();
				if($damager instanceof Player){
					if($this->players[$damager->getName()][2] === self::TEAM_BLUE){
						$damagerColor = TextFormat::BLUE;
						$playerColor = TextFormat::RED;
						$this->score[self::TEAM_BLUE]++;
					}else{
						$damagerColor = TextFormat::RED;
						$playerColor = TextFormat::BLUE;
						$this->score[self::TEAM_RED]++;
					}
					$firstKill = "";
					if($this->score[self::TEAM_BLUE] + $this->score[self::TEAM_RED] <= 1){
						$firstKill = TextFormat::YELLOW."FIRST BLOOD\n".TextFormat::WHITE;
					}
					$this->broadcastPopup($firstKill.$damagerColor.$damager->getName().TextFormat::WHITE." -O-> ".$playerColor.$player->getName());
					
					++$this->killDeath[0][$damager->getName()];
					++$this->killDeath[1][$player->getName()];
					
					$this->level[$damager->getName()] += ($damager->getHealth() * 5);
					$level = floor(($this->level[$damager->getName()] / 10000));
					$damager->setNameTag("Lv.".$level.$damagerColor.$damager->getName());
				}
			}
			$event->setDeathMessage("");
		}
		
		$items = $event->getDrops();
		foreach($items as $key => $item){
			if($item->getId() !== self::GUN_ID){
				unset($items[$key]);
			}
		}
		$event->setDrops($items);
	}
	
	public function onRespawn(PlayerRespawnEvent $event){
		$player = $event->getPlayer();
		
		$this->getServer()->getScheduler()->scheduleDelayedTask(new TeleportTask($this, $player->getName()), 5);
		
		if(!$player->getInventory()->contains(Item::get(self::GRENADE_ID))){
			$player->getInventory()->addItem(Item::get(self::GRENADE_ID, 0, 2));
		}
		
		if(!$player->getInventory()->contains(Item::get(self::GUN_ID))){
			$player->getInventory()->addItem(Item::get(self::GUN_ID));
		}
		
		$this->players[$player->getName()][3] = time();
		if(isset($this->players[$player->getName()][0])){
			$this->players[$player->getName()][0]->setAmmo(50);
		}
	}
	
	public function onDamage(EntityDamageEvent $event){
		$player = $event->getEntity();
		if($player instanceof Player){
			if($this->status !== self::STAT_GAME_IN_PROGRESS){
				$event->setCancelled();
				return;
			}
			if((time() - $this->players[$player->getName()][3]) < 3){
				$event->setCancelled();
				return;
			}
			if($event instanceof EntityDamageByEntityEvent){
				$damager = $event->getDamager();
				$event->setKnockBack(0.2);
				if($damager instanceof Player){
					if(!$this->isEnemy($player->getName(), $damager->getName())){
						$event->setCancelled();
					}
				}
			}
		}
	}
	
	public function onDropItem(PlayerDropItemEvent $event){
		$item = $event->getItem();
		
		if($item->getId() === self::GRENADE_ID){
			$event->setCancelled();
		}elseif($item->getId() === self::GUN_ID){
			$event->setCancelled();
		}
	}
	
	public function onPickup(InventoryPickupItemEvent $event){
		$player = $event->getInventory()->getHolder();
		
		if($player instanceof Player){
			if($event->getItem()->getItem()->getId() === self::GUN_ID){
				$this->players[$player->getName()][0]->addAmmo(30);
				if($player->getInventory()->contains(Item::get(self::GUN_ID))){
					$event->getItem()->kill();
					$event->setCancelled();
				}
			}else{
				$event->getItem()->kill();
			}
		}
	}
}