<?php
namespace ZipPluginLoader;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\event\plugin\PluginEnableEvent;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginLoader;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\utils\PluginException;
class ZipPluginLoader implements PluginLoader{
	const PREFIX = "myzip://";
	const PLUGIN_YML = "plugin.yml";
	const ZIP_EXT = ".zip";
	const CANARY = "#multi-loader.zip";
	/** @var Server */
	private $server;
	
	public function __construct(\ClassLoader $loader){
		$this->loader = $loader;
	}
 	public function canLoadPlugin(string $path) : bool{
		return is_dir($path) and file_exists($path . "/plugin.yml") and file_exists($path . "/src/");
	}
	/**
	 * Gets the PluginDescription from the file
	 *
	 * @param string $file
	 *
	 * @return PluginDescription
	 */
	public function getPluginDescription(string $file) : ?PluginDescription{//@API
		if (substr($file,0,strlen(self::PREFIX)) == self::PREFIX) {
			if (substr($file,-strlen(self::CANARY)) == self::CANARY) {
				// This is an internal path
				$file = substr($file,0,strlen($file)-strlen(self::CANARY));
			}
			return $this->myGetPluginDesc($file);
		}
		$ymls = $this->findFiles($file,"plugin.yml");
		if ($ymls === null) return null;
		if (count($ymls) > 1) {
			$plugins = $this->check_plugins($file,$ymls);
			return $this->getDummyDesc($plugins,$file);
		}
		return $this->myGetPluginDesc(self::PREFIX.$file."#".$ymls[0]);
	}
	public function loadPlugin(string $file) : void{
		$this->loader->addPath("$file/src");
	}
	/**
	 * @param Plugin $plugin
	 */
	public function enablePlugin(Plugin $plugin){//@API
		if($plugin instanceof PluginBase and !$plugin->isEnabled()){
			$this->server->getLogger()->info("[ZipPluginLoader] Enabling " . $plugin->getDescription()->getFullName());
			$plugin->setEnabled(true);
			Server::getInstance()->getPluginManager()->callEvent(new PluginEnableEvent($plugin));
		}
	}
	/**
	 * @param Plugin $plugin
	 */
	public function disablePlugin(Plugin $plugin){//@API
		if($plugin instanceof PluginBase and $plugin->isEnabled()){
			$this->server->getLogger()->info("[ZipPluginLoader] Disabling " . $plugin->getDescription()->getFullName());
			Server::getInstance()->getPluginManager()->callEvent(new PluginDisableEvent($plugin));
			$plugin->setEnabled(false);
		}
	}
	/********************************************************************/
	protected function getDummyDesc($plugins,$file) {
		$name = preg_replace('/\.zip$/i',"",basename($file));
		$ch = [
			"name" => "_". $name,
			"version" => "zipFile",
			"main" => "ZipPluginLoader\\Dummy",
			"description" => "Plugin Wrapper for loading ".$name,
		];
		foreach (["api","authors"] as $key) {
			$ch[$key] = [];
			foreach ($plugins as $pp) {
				if (!isset($pp[$key])) continue;
				foreach ($pp[$key] as $a) {
					if (isset($ch[$key][$a])) continue;
					$ch[$key][$a] = $a;
				}
			}
			$ch[$key] = array_values($ch[$key]);
		}
		foreach (["depend","softdepend","loadbefore"] as $key) {
			$ch[$key] = [];
			foreach ($plugins as $pp) {
				if (!isset($pp[$key])) continue;
				foreach ($pp[$key] as $a) {
					if (isset($plugins[$a])) continue; // Internal depedency
					if (isset($ch[$key][$a])) continue;
					$ch[$key][$a] = $a;
				}
			}
			$ch[$key] = array_values($ch[$key]);
		}
		return new PluginDescription(yaml_emit($ch,YAML_UTF8_ENCODING));
	}
	protected function myGetPluginDesc($file) {
		if (substr($file,0,strlen(self::PREFIX)) != self::PREFIX) {
			$file = self::PREFIX . $file;
		}
		if (substr($file,-strlen(self::PLUGIN_YML)) != self::PLUGIN_YML) {
			if (substr($file,-strlen(self::ZIP_EXT)) == self::ZIP_EXT) {
				$file .= "#".self::PLUGIN_YML;
			} else {
				switch(substr($file,-1)) {
					case "/":
					case "#":
						break;
					default:
						$file .= "/";
				}
				$file .= self::PLUGIN_YML;
			}
		}
		$yaml = @file_get_contents($file);
		if ($yaml == "") return null;
		return new PluginDescription($yaml);
	}
	protected function check_plugins($file,$ymls) {
		$plugins = [];
		// Check if there is a control file
		$ok = false;
		$ctl = preg_replace('/\.zip$/i','.ctl',$file);
		if (file_exists($ctl)) {
			$ctl = file($ctl,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
			$ok = [];
			foreach($ctl as $i) {
				$i = trim($i);
				if (substr($i,0,1) == ";" || substr($i,0,1) == "#") continue;
				$ok[$i] = $i;
			}
		}
		foreach ($ymls as $plugin_yml) {
			$dat = @file_get_contents(self::PREFIX.$file."#".$plugin_yml);
			if ($dat == "") continue;
			$dat = yaml_parse($dat);
			$plugin = [];
			foreach (["name","version","main"] as $str) {
				if (!isset($dat[$str])) {
					throw new PluginException("Invalid $plugin_yml");
					return null;
				}
				$plugin[$str] = $dat[$str];
			}
			if ($ok) {
				// Filter out plugins not listed in control file
				if (!isset($ok[$dat["name"]])) continue;
			}
			if (!isset($dat["api"])) {
				throw new PluginException("[ZipPluginLoader] No API defined in $plugin_yml");
				return null;
			}
			$plugin["api"] = is_array($dat["api"]) ? $dat["api"] : [$dat["api"]];
			$plugin["path"] = self::PREFIX.$file."#".
								 ($plugin_yml == self::PLUGIN_YML ? "" :
								  dirname($plugin_yml)."/");
			foreach(["website","description","prefix","load"] as $str) {
				if (isset($dat[$str])) $plugin[$str] = $dat[$str];
			}
			$plugin["authors"] = [];
			if (isset($dat["author"])) $plugin["authors"][] = $dat["author"];
			if (isset($dat["authors"])) {
				foreach($dat["authors"] as $a) {
					$plugin["authors"][] = $a;
				}
			}
			foreach(["depend","loadBefore","softdepend"] as $arr) {
				$plugin[$arr] = isset($dat[$arr]) ? (array)$dat[$arr] : [];
			}
			foreach(["commands","permissions"] as $arr) {
				if (isset($dat[$arr]) && is_array($dat[$arr])) {
					$plugin[$arr] = $dat[$arr];
				}
			}
			$plugins[$plugin["name"]] = $plugin;
		}
		return $plugins;
	}
	protected function findFiles($zip,$file,$warnphar = false) {
		$files = [];
		$za = new \ZipArchive();
		if($za->open($zip) !== true) return null;
		// Look for plugin data...
		$basepath = null;
		for ($i=0;$i < $za->numFiles;$i++) {
			$st = $za->statIndex($i);
			if (!isset($st["name"])) continue;
			if (basename($st["name"]) == $file) {
				$files[] = $st["name"];
				continue;
			}
			if (preg_match('/\.phar$/i',$st["name"])) {
				$this->server->getLogger()->warning("[ZipPluginLoader] Skipping PHAR file: ".$st["name"]);
			}
		}
		$za->close();
		unset($za);
		if (count($files)) return $files;
		return null;
	}
	protected function zipdir($ff) {
		if (substr($ff,0,strlen(self::PREFIX)) == self::PREFIX) {
			$ff = substr($ff,strlen(self::PREFIX));
		}
		$p = strpos($ff,"#");
		if ($p !== false) {
			$ff = substr($ff,0,$p);
		}
		return dirname($ff);
	}
/**/
	public function getAccessProtocol() : string{
}
}
