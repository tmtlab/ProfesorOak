<?php

class Pokemon extends TelegramApp\Module {
	protected $runCommands = FALSE;
	private $misspells = array();
	private $GAMEMASTER = NULL;
	private $GAMEMASTER_FAST = array();
	private $loadedNames = array();

	public function __construct($name = NULL){
		// Start when needed.
		// $this->GAMEMASTER = json_decode(file_get_contents("GAME_MASTER.json"));
		$folder = dirname(__FILE__) ."/Pokemon/";
		foreach(scandir($folder) as $file){
			if(substr($file, -4) == ".php"){ require_once $folder .$file; }
		}
	}

	public function gamemaster($key){
		if($this->GAMEMASTER == NULL){
			$this->GAMEMASTER = json_decode(file_get_contents("GAME_MASTER.json"));
		}
		if(array_key_exists($key, $this->GAMEMASTER_FAST)){
			return $this->GAMEMASTER_FAST[$key];
		}
		foreach($this->GAMEMASTER->itemTemplates as $data){
			if($data->templateId == $key){
				$this->GAMEMASTER_FAST[$key] = $data;
				return $data;
			}
		}
		return NULL;
	}

	public function misspell($name, $retnum = FALSE){
		$name = strtolower($name);
		if(substr($name, -1) == 's'){ $name = substr($name, 0, -1); } // Plural

		if(empty($this->misspells)){ $this->load_misspells(); }

		if(array_key_exists($name, $this->misspells)){
			if($retnum){ return $this->misspells[$name]; } // Return Pokémon ID.
			return $this->name($this->misspells[$name]); // ELSE Return Pokémon Name.
		}
	}

	private function load_misspells(){
		$load = $this->db->get('pokemon_misspell', NULL, 'word, pokemon');
		$load = array_column($load, 'pokemon', 'word');

		$this->misspells = $load;
		return $load;
	}

	// TODO Return what?
	public function misspell_multi($str){
		if(is_string($str)){ $str = explode(" ", $str); }
		// TODO Join Pokémon real name
		$res = $this->db
			->where('word', $str, 'IN')
		->get('pokemon_misspell');
		if($this->db->count == 0){ return FALSE; } // All OK.

		$str = implode(" ", $str);
		// $str = str_ireplace()
	}

	public function parse_text($string = NULL, $first = TRUE, $get_pokemon = FALSE){
		// $first es si solo habrá un Pokémon en la frase.
		// Si hay "mime", busca "Mr. Mime" y sus variantes.
		// Y lo mismo con los Nidoran.
		if(empty($string)){ $string = $this->telegram->text(); }
		// $string = strtolower($string);
		// $string = explode(" ", $string);
		$findings = array();

		// Load misspells
		if(empty($this->misspells)){ $this->load_misspells(); }
		$misnames = array_keys($this->misspells);
		foreach($misnames as $k => $v){
			$misnames[$k] = str_replace(".", '\.', $v);
		}
		$pokemonList = $this->GetNames(TRUE, "POKEMON");
		$misnames = implode("|", $misnames) .'|' .implode("|", array_values($pokemonList));

		$r = preg_match_all("/(#(?P<id>\d{1,3})\b|(?P<name>$misnames)\b)/i", $string, $matches);
		if($r){
			foreach($matches["id"] as $k => $n){
				if(!empty($n)){
					$findings[] = (int) $n;
				}
			}
			foreach($matches["name"] as $k => $n){
				if(!empty($n)){
					if(array_key_exists($n, $this->misspells)){
						$findings[] = $this->misspells[$n];
					}else{
						$search = array_search(strtoupper($n), $pokemonList);
						if($search !== FALSE){ $findings[] = $search; }
					}
				}
			}
		}

		if($first){
			if(!isset($findings[0])){ return NULL; }
			if($get_pokemon and is_numeric($findings[0])){
				return $this->Get($findings[0]);
			}
			return $findings[0];
		}

		return $findings;
	}

	public function GymID($id){
		return $this->db
			->where('uuid', $id)
			->where('gym', TRUE)
			->where('disabled', FALSE)
		->getOne('pokestops');
	}

	public function GymSearchNear($search, $location, $radius = 160, $limit = 1){
		$locsql = $this->Functions->location_search_sql('lat', 'lng', $location->latitude, $location->longitude);
		$gyms = $this->db
			->where('gym', TRUE)
			->where('title', $search, 'LIKE')
			->where("($locsql) <= $radius")
		->get('pokestops', $limit, "uuid, title, lat, lng, ($locsql) AS distance");
		if(count($gyms) == 1){ return current($gyms); }
		return $gyms;
	}

	public function GymNear($location, $radius = 160, $limit = 10){
		$locsql = $this->Functions->location_search_sql('lat', 'lng', $location->latitude, $location->longitude);
		$gyms = $this->db
			->where('gym', TRUE)
			->where("($locsql) <= $radius")
		->get('pokestops', $limit, "uuid, title, lat, lng, ($locsql) AS distance");
		return $gyms;
	}

	// -----------------------------------

	public function PlayerLevel($exp){
		$data = $this->gamemaster('PLAYER_LEVEL_SETTINGS');
		foreach($data->playerLevel->requiredExperience as $lvl => $minexp){
			if($exp > $minexp){ continue; }
			return $lvl;
		}
	}

	public function CPScalar($lvl){
		$half = (is_float($lvl) and $lvl < 40);
		$lvl = min(floor($lvl), 40) - 1;
		$data = $this->gamemaster('PLAYER_LEVEL_SETTINGS');
		$cpcur = $data->playerLevel->cpMultiplier[$lvl];
		if(!$half){ return $cpcur; }

		// Source: https://pokemongo.gamepress.gg/cp-multiplier
		$cpnext = $data->playerLevel->cpMultiplier[$lvl + 1];
		$cpmstep = (pow($cpnext, 2) - pow($cpcur, 2) / 2);
		return sqrt($cpcur + $cpmstep);
	}

	public function CalculateCP($pokemon, $cpm, $ivAtk, $ivDef = NULL, $ivSta = NULL){
		if(!is_object($pokemon)){ $pokemon = $this->Get($pokemon); }
		if($cpm >= 1){ $cpm = $this->CPScalar($cpm); } // LVL -> CPM
		if(empty($ivSta) and empty($ivDef) and is_array($ivAtk)){
			$ivSta = $ivAtk[2];
			$ivDef = $ivAtk[1];
			$ivAtk = $itAtk[0];
		}
		$atk = ($pokemon->stats->baseAttack + $ivAtk) * $cpm;
		$def = ($pokemon->stats->baseDefense + $ivDef) * $cpm;
		$sta = ($pokemon->stats->baseStamina + $ivSta) * $cpm;
		return max(10, (floor(
			pow($sta, 0.5) *
			$atk *
			pow($def, 0.5)
			/ 10
		)));
	}

	public function CalculateHP($pokemon, $cpm, $ivSta){
		if(!is_object($pokemon)){ $pokemon = $this->Get($pokemon); }
		if($cpm >= 1){ $cpm = $this->CPScalar($cpm); } // LVL -> CPM

		return max(10, floor(
			($pokemon->stats->baseStamina + $ivSta) * $cpm
		));
	}

	public function CalculateIV($pokemon, $cp, $hp, $stardust, $extra = array()){
		$half = (
			(isset($extra['powered']) and $extra['powered']) or
			$extra === TRUE
		);
		$table = array();
		$low = 100; $high = 0; // HACK
		foreach($this->CostGetLevels($stardust, $half) as $level){
			$cpmul = $this->CPScalar($level);
			for($ivSta = 0; $ivSta <= 15; $ivSta++){
				if($hp != $this->CalculateHP($pokemon, $cpmul, $ivSta)){ continue; }

				for($ivDef = 0; $ivDef <= 15; $ivDef++){
					for($ivAtk = 0; $ivAtk <= 15; $ivAtk++){
						// Si el CP calculado coincide con el nuestro, agregar posibilidad.
						if($cp != $this->CalculateCP($pokemon, $cpmul, $ivAtk, $ivDef, $ivSta)){ continue; }

						$sum = (($ivAtk + $ivDef + $ivSta) / 45) * 100;
						if($sum > $high){ $high = $sum; }
						if($sum < $low){ $low = $sum; }
						$table[] = ['level' => $level, 'atk' => $ivAtk, 'def' => $ivDef, 'sta' => $ivSta];
					}
				}
			}
		}

		if(count($table) > 1 and (isset($extra['attack']) or isset($extra['defense']) or isset($extra['stamina']))){
			// si tiene ATK, DEF O STA, los resultados
			// que lo superen, quedan descartados.
			foreach($table as $i => $r){
				if
				(
					( $extra['attack'] and (
						( max($r['atk'], $r['def'], $r['sta']) != $r['atk'] ) or
						( isset($extra['ivcalc']) and !in_array($r['atk'], $extra['ivcalc']) )
					) ) or (
					$extra['defense'] and (
						( max($r['atk'], $r['def'], $r['sta']) != $r['def'] ) or
						( isset($extra['ivcalc']) and !in_array($r['def'], $extra['ivcalc']) )
					) ) or (
					$extra['stamina'] and (
						( max($r['atk'], $r['def'], $r['sta']) != $r['sta'] ) or
						( isset($extra['ivcalc']) and !in_array($r['sta'], $extra['ivcalc']) )
					) ) or (
						(!$extra['attack'] or !$extra['defense'] or !$extra['stamina']) and
						($r['atk'] + $r['def'] + $r['sta'] == 45)
					)
				){ unset($table[$i]); continue; }
			}
			$low = 100; $high = 0;
			foreach($table as $r){
				$sum = (($r['atk'] + $r['def'] + $r['sta']) / 45) * 100;
				if($sum > $high){ $high = $sum; }
				if($sum < $low){ $low = $sum; }
			}
		}

		return $table;
	}

	public function CandyCost($lvl){
		$lvl = min(floor($lvl), 40) - 1; // Round down
		$data = $this->gamemaster('POKEMON_UPGRADE_SETTINGS');
		return $data->pokemonUpgrades->candyCost[$lvl];
	}

	public function StardustCost($lvl){
		$lvl = min(floor($lvl), 40) - 1; // Round down
		$data = $this->gamemaster('POKEMON_UPGRADE_SETTINGS');
		return $data->pokemonUpgrades->stardustCost[$lvl];
	}

	// For Candy and Stardust
	public function CostGetLevels($amount, $half = FALSE){
		$data = $this->gamemaster('POKEMON_UPGRADE_SETTINGS');
		$key = ($amount <= 15 ? "candy" : "stardust") ."Cost";
		$levels = array();
		foreach($data->pokemonUpgrades->{$key} as $lvl => $cost){
			if($cost == $amount){
				$levels[] = ($lvl + 1);
				if($half){ $levels[] = ($lvl + 1.5); }
			}
		}
		return $levels;
	}

	public function BadgeLevel($badge, $amount){
		if(is_numeric($badge)){
			// TODO INT -> STRVAL
		}elseif(strpos($badge, "BADGE_") !== 0){
			$badge = "BADGE_$badge"; // ADD prefix if removed
		}

		$data = $this->gamemaster($badge);
		if(!$data){ return NULL; }
		$lvl = 0;
		foreach($data->badgeSettings->targets as $minval){
			if($amount >= $minval){ $lvl++; }
		}
		return $lvl;
	}

	public function Get($search, $retkey = FALSE){
		if(empty($search)){ return NULL; }
		$key = NULL;

		// Search common name.
		if(!is_numeric($search)){
			$tmpsearch = array_search(strtoupper($search), $this->GetNames(TRUE, "POKEMON"));
			if($tmpsearch){
				$key = "V" .str_pad($tmpsearch, 4, "0", STR_PAD_LEFT) ."_POKEMON_" .strtoupper($search);
				return ($retkey ? $key : $this->gamemaster($key)->pokemonSettings);
			}
		}

		// ------------------
		// Still not found? Misspells.
		if(is_string($search) and strlen($search) <= 12){
			$tmpsearch = $this->misspell($search, TRUE);
			if($tmpsearch){ $search = $tmpsearch; }
		}

		// Generate Key
		if(is_numeric($search)){
			$name = $this->GetNames($search, "POKEMON");
			if(!$name){ return NULL; }
			$key = "V" .str_pad($search, 4, "0", STR_PAD_LEFT) ."_POKEMON_" .$name;
			if($retkey){ return $key; }
		}

		if(empty($key)){ return FALSE; }
		$data = $this->gamemaster($key);
		return ($data ? $data->pokemonSettings : NULL);
	}

	public function GetMovement($search, $retkey = FALSE){
		if(empty($search)){ return NULL; }
		$key = NULL;
		// Id, CodeName, MT/MO, Type, Attack, Bars, TimeRun, TImeDelay
		// energyDelta es positiva si es FAST (ataque normal)
		// Es negativa -33, -50 o -100 | barras.

		// Buscar por traduccion -> numero
		if(is_string($search)){
			$tmpsearch = array_search(strtoupper($search), $this->GetNames(TRUE, "MOVE"));
			if($tmpsearch){
				$key = "V" .str_pad($tmpsearch, 4, "0", STR_PAD_LEFT) ."_MOVE_" .strtoupper($search);
				return ($retkey ? $key : $this->gamemaster($key)->moveSettings);
			}
		}

		if(is_numeric($search)){
			$name = $this->GetNames($search, "MOVE");
			if(!$name){ return NULL; }
			$key = "V" .str_pad($search, 4, "0", STR_PAD_LEFT) ."_MOVE_" .$name;
			if($retkey){ return $key; }
		}

		$data = $this->gamemaster($key);
		return ($data ? $data->moveSettings : NULL);
	}

	public function GetLegendaries($search = NULL){
		$legendaries = array();
		$names = $this->GetNames(TRUE, "POKEMON");
		if(is_string($search)){ $search = $this->GetNames($search, "POKEMON"); } // -> int
		foreach($names as $id => $name){
			$key = "V" .str_pad($id, 4, "0", STR_PAD_LEFT) ."_POKEMON_" .$name;
			$pokemon = $this->Get($key);
			if($search and $search == $id){
				return isset($pokemon->rarity);
				// and $pokemon->rarity == "POKEMON_RARITY_LEGENDARY"
			}elseif(isset($pokemon->rarity)){
				$legendaries[] = $id;
			}
		}
		return $legendaries;
	}

	public function ResolveName($pokemon, $lang = "en"){
		if(is_numeric($pokemon)){ $pokemon = $this->Get($pokemon); }
		if(is_object($pokemon)){ $pokemon = $pokemon->pokemonId; }
		if(!is_string($pokemon)){ return FALSE; }

		// Si es idioma estándar y Pokémon general (no especial), transforma.
		if($lang == "en" and strpos($pokemon, "_") === FALSE){
			return ucwords(strtolower($pokemon));
		}

		// Si la entrada ya existe directamente...
		$langs = array_unique([$lang, "en"]);
		foreach($langs as $lang){
			$res = ($this->strings->get_multi('GAME_MASTER_POKEMON', strtoupper($pokemon), $lang));
			if($res){ return $res; }
		}

		// Si ha indicado el propio nombre...
		// Array search no buscará comparando mayus/minus, y ucwords no siempre es viable.
		$pokemonList = $this->strings->get('GAME_MASTER_POKEMON');
		$len = strlen($pokemon);
		foreach($pokemonList as $index => $name){
			if(
				stripos($name, $pokemon) === 0 and
				strlen($name) == $len
			){ return $name; }
		}

		return FALSE;
	}

	public function GetNames($id = TRUE, $search = "POKEMON"){
		if(isset($this->loadedNames[$search])){
			if(is_numeric($id) and array_key_exists($id, $this->loadedNames[$search])){
				return $this->loadedNames[$search][$id];
			}
			return $this->loadedNames[$search];
		}
		$info = array();
		$find = FALSE;
		$this->gamemaster('PLAYER_LEVEL_SETTINGS'); // Force NULL load
		foreach($this->GAMEMASTER->itemTemplates as $data){
			$key = strval($data->templateId);
			$r = preg_match_all('/^V(?P<ID>[\d]{4})_'.$search.'_(?P<NAME>[^\s]+)$/i', $key, $matches);
			if($r){
				$idp = intval($matches["ID"][0]);
				if($id === $idp){ $find = $matches["NAME"][0]; }

				$info[] = [
					'ID' => $idp,
					'NAME' => $matches["NAME"][0]
				];
			}
		}
		$data = $this->loadedNames;
		$data[$search] = array_column($info, 'NAME', 'ID');
		$this->loadedNames = $data;
		if($id !== TRUE){ return $find; }
		return $data[$search];
	}

	public function GetType($search = TRUE){
		$names = [
			"NONE", "NORMAL", "FIGHTING", "FLYING", "POISON", "GROUND",
			"ROCK", "BUG", "GHOST", "STEEL", "FIRE", "WATER", "GRASS",
			"ELECTRIC", "PSYCHIC", "ICE", "DRAGON", "DARK", "FAIRY"
		];
		if(is_string($search)){
			$search = strtoupper($search);
			$search = str_replace("POKEMON_TYPE_", "", $search);
			return array_search($search, $names);
		}elseif(is_numeric($search)){
			if(array_key_exists($search, $names)){ return $names[$search]; }
		}elseif($search === TRUE){
			return $names;
		}
		return FALSE;
	}

	public function GetTypeIcon($type, $resolveUni = TRUE){
		if(!is_numeric($type)){ $type = $this->GetType($type); }
		$icons = [
			'',
			["\u{1F937}\u{200D}\u{2642}\u{FE0F}", ':man_shrugging:'], // NORMAL
			["\u{1F44A}", ':punch:'], // FIGHTING
			["\u{1F4A8}", ':dash:'], // FLYING
			["\u{1F5A4}", ':black_heart:'], // POISON
			["\u{1F463}", ':footprints:'], // GROUND
			["\u{2604}\u{FE0F}", ':comet:'], // ROCK
			["\u{1F41B}", ':bug:'], // BUG
			["\u{1F47B}", ':ghost:'], // GHOST
			["\u{1F918}", ':metal:'], // STEEL
			["\u{1F525}", ':fire:'], // FIRE
			["\u{1F4A7}", ':droplet:'], // WATER
			["\u{1F343}", ':leaves:'], // GRASS
			["\u{26A1}", ':zap:'], // ELECTRIC
			["\u{1F300}", ':cyclone:'], // PSYCHIC
			["\u{2744}\u{FE0F}", ':snowflake:'], // ICE
			["\u{1F409}", ':dragon:'], // DRAGON
			["\u{1F31A}", ':new_moon_with_face:'], // DARK (:new_moon:)
			["\u{1F9DA}\u{200D}\u{2640}\u{FE0F}", ':woman_fairy:'], // FAIRY, NEW (does not exist on all platform),
		];
		$resolveUni = (int) !$resolveUni;
		if(is_numeric($type) and isset($icons[$type])){ return $icons[$type][$resolveUni]; }
		return NULL;
	}

	public function TypeScalar($type, $toTarget = NULL){
		if(is_numeric($type)){ $type = $this->GetType($type); }
		else{
			$type = strtoupper($type);
			$type = str_replace("POKEMON_TYPE_", "", $type);
		}

		$data = $this->gamemaster("POKEMON_TYPE_$type");
		if(!$data){ return FALSE; }

		if($toTarget === NULL){ return $data->typeEffective->attackScalar; }
		if(is_string($toTarget)){
			$toTarget = strtoupper($toTarget);
			$toTarget = str_replace("POKEMON_TYPE_", "", $toTarget);
			$toTarget = $this->GetType($toTarget);
		}

		return $data->typeEffective->attackScalar[$toTarget - 1];
	}

	// Tipos que me afectan
	public function TypeAffects($me){
		if(is_string($me)){ $me = $this->GetType($me); }
		$types = $this->GetType(TRUE);
		$affects = array();

		foreach($types as $pos => $type){
			$data = $this->gamemaster("POKEMON_TYPE_$type");
			if(!$data){ continue; } // NONE TYPE
			$affects[$pos] = $data->typeEffective->attackScalar[$me];
		}

		return $affects;
	}


	public function MovementBest($pokemon, $str = FALSE){
		// $pokemon = $this->load($pokemon);
	}

	public function MovementWorst($pokemon, $str = FALSE){
		// $pokemon = $this->load($pokemon);
	}

	// -----------------------------------

	public function parser($text){
		$pokes = array(); // $pokemon->pokedex();
		// $s = explode(" ", $pokemon->misspell($telegram->text()));
		$data = array();
		$number = NULL;
		$hashtag = FALSE;
		// ---------
		$data['pokemon'] = NULL;
		foreach($s as $w){
			$hashtag = ($w[0] == "#" and strlen($w) > 1);
			$w = preg_replace("/[^a-zA-Z0-9]+/", "", $w);
			$w = strtolower($w);

			if($data['pokemon'] === NULL){
				foreach($pokes as $pk){
					if($w == strtolower($pk->name)){ $data['pokemon'] = $pk->id; break; }
				}
			}

			if(is_numeric($w)){
				// tengo un número pero no se de qué. se supone que la siguiente palabra me lo dirá.
				// a no ser que la palabra sea un "DE", en cuyo caso paso a la siguiente.
				if($hashtag == TRUE and $data['pokemon'] === NULL){
					$data['pokemon'] = (int) $w;
				}else{
					$number = (int) $w;
				}
			}

			// Buscar distancia
			if(substr($w, -1) == "m"){ // Metros
				$n = substr($w, 0, -1);
				if(!is_numeric($n) && substr($n, -1) == "k"){ // Kilometros
					$n = substr($n, 0, -1);
					if(is_numeric($n)){ $n = $n * 1000; }
				}
				if(is_numeric($n)){
					$data['distance'] = $n;
				}
			}

			// Si se escribe numero junto a palabra, separar
			$conj = ['cp', 'pc', 'hp', 'ps'];
			foreach($conj as $wf){
				if(substr($w, -2) == $wf){
					$n = substr($w, 0, -2);
					if(is_numeric($n)){
						$number = $n;
						$w = $wf;
					}
				}
			}

			$search = ['cp', 'pc', 'hp', 'ps', 'polvo', 'polvos', 'caramelo', 'polvoestelar', 'stardust', 'm', 'metro', 'km'];
			$enter = FALSE;
			foreach($search as $q){
				if(strpos($w, $q) !== FALSE){ $enter = TRUE; break; }
			}
			if($enter){
				$action = NULL;
				if(strpos($w, 'cp') !== FALSE or strpos($w, 'pc') !== FALSE){ $action = 'cp'; }
				if(strpos($w, 'hp') !== FALSE or strpos($w, 'ps') !== FALSE){ $action = 'hp'; }
				if(strpos($w, 'polvo') !== FALSE or strpos($w, 'stardust') !== FALSE or strpos($w, 'polvoestelar') !== FALSE){ $action = 'stardust'; }
				if(strpos($w, 'm') !== FALSE && strlen($w) == 1){ $action = 'distance'; }
				if(strpos($w, 'caramelo') !== FALSE){ $action = 'candy'; }
				if(strpos($w, 'metro') !== FALSE){ $action = 'distance'; }
				if(strpos($w, 'km') !== FALSE && strlen($w) == 2){ $action = 'distance'; $number = $number * 1000; }

				if(strlen($w) > 2 && $number === NULL){
					// Creo que me lo ha puesto junto. Voy a sacar números...
					$number = filter_var($w, FILTER_SANITIZE_NUMBER_INT);
				}

				if(
					(!empty($number) && !empty($action)) and
					( ($action == 'hp' && $number > 5 && $number < 300) or
					($action == 'stardust' && $number > 200 && $number <= 10000) or
					($action == 'distance') or
					($number > 5 && $number < 4000) )
				){
					$data[$action] = $number;
					$number = NULL;
				}
			}
		}

		// TODO CHECK
		/* $data['attack'] = ($telegram->text_has(["ataque", "ATQ", "ATK"]));
		$data['defense'] = ($telegram->text_has(["defensa", "DEF"]));
		$data['stamina'] = ($telegram->text_has(["salud", "stamina", "estamina", "STA"]));
		$data['powered'] = ($telegram->text_has(["mejorado", "entrenado", "powered"]) && !$telegram->text_has(["sin", "no"], ["mejorar", "mejorado"]));
		$data['egg'] = ($telegram->text_contains("huevo"));

		if($telegram->text_has(["muy fuerte", "lo mejor", "flipando", "fuera de", "muy fuertes", "muy alto", "muy alta", "muy altas"])){ $data['ivcalc'] = [15]; }
		if($telegram->text_has(["bueno", "bastante bien", "buenas", "normal", "muy bien"])){ $data['ivcalc'] = [8,9,10,11,12]; }
		if($telegram->text_has(["bajo", "muy bajo", "poco que desear", "bien"])){ $data['ivcalc'] = [0,1,2,3,4,5,6,7]; }
		if($telegram->text_has(["fuerte", "fuertes", "excelente", "excelentes", "impresionante", "impresionantes", "alto", "alta"])){ $data['ivcalc'] = [13,14]; } */

		return $data;
	}

	public function pokedex_text($pokemon, $parseEmoji = TRUE){
		$str = ":id: "  ." - "  .$this->ResolveName($pokemon) . " " .$this->GetTypeIcon($pokemon->type, $parseEmoji);
		if(isset($pokemon->type2)){ $str .= " / " .$this->GetTypeIcon($pokemon->type2, $parseEmoji); }
		$str .= "\n";

		$str .= ":crossed_swords: " .$pokemon->stats->baseAttack
				." :shield: " .$pokemon->stats->baseDefense
				." :heartbeat: " .$pokemon->stats->baseStamina
				."\n";

		$str .= "\n";

		foreach(["quickMoves", "cinematicMoves"] as $selector){
			foreach($pokemon->{$selector} as $movename){
				$move = $this->GetMovement($movename);
				$bars = 0;
				if($move->energyDelta < 0){
					$bars = (4 - round(abs($move->energyDelta) / 33));
				}
				$str .= $this->GetTypeIcon($move->pokemonType, $parseEmoji) ." "
						.(int) $move->power .($bars > 0 ? "/".$bars : "")  ." - "
						.$this->strings->get_multi('GAME_MASTER_MOVES', $movename)
						."\n";
			}
			$str .= "\n";
		}

		return ($parseEmoji ? $this->telegram->emoji($str) : $str);
	}

	protected function hooks(){
		if(
			$this->telegram->text_command("iv") or
			$this->telegram->text_command("ivs")
		){
			if($this->telegram->words() < 5){
				$this->telegram->send
					->notification(FALSE)
					->text($this->strings->get('pokemon_calc_command_help'), 'HTML')
				->send();
				$this->end();
			}else{
				$args = $this->telegram->words(TRUE);
				array_shift($args); // Remove command
				$ivs = $this->iv($args);
			}
		}

		elseif(
			$this->telegram->text_regex("^confirm(o|ar) nido (de )?{pokemon} (en )?{S:location}") and
			!$this->telegram->has_forward
		){
			$pokemon = $this->load($this->telegram->input->pokemon);
			if(!$pokemon){
				$this->telegram->send
					->text(":question: ¿Qué Pokémon?")
				->send();
				$this->end();
			}
			if(in_array($this->user->flags, ['fly'])){
				// TODO Log.
				// TODO Send to Admin chat - once?
				$this->end();
			}
			if(!$this->user->verified){

				$this->end();
			}
			$trustlvl = $this->chat->settings('trust');
			if($trustlvl and $this->user->trust($this->chat) < $trustlvl){
				$this->end();
			}
		}

		elseif(
			$this->telegram->text_regex(["^pokedex #?{N:pokemon}$", "^pokedex {S:pokemon}"]) and
			$this->telegram->words() <= 5 and
			!$this->telegram->has_forward
		){
			if(stripos($this->telegram->input->pokemon, "Mr") === 0){
				$this->telegram->input->pokemon = "MR_MIME";
			}

			$pokemon = $this->Get($this->telegram->input->pokemon);
			if(!$pokemon){ $this->end(); }

			$str = $this->pokedex_text($pokemon);

			$this->telegram->send
				->text($str)
			->send();
			$this->end();
		}
	}
}
