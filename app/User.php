<?php

class User extends TelegramApp\User {
	public function __construct($input = NULL, $db = NULL){
		  parent::__construct($input, $db);
	}

	// Custom Properties
	// Flags
	// Settings

	private function set_chat($chat = NULL){
		if($chat !== NULL){ $this->chat = $chat; }
		else{ $chat = $this->chat; }
		return $chat;
	}

	function ban($chat = NULL){
		$chat = $this->set_chat($chat);
		return ($this->telegram->send->ban($this->id, $chat) !== FALSE);
	}

	function kick($chat = NULL){
		$chat = $this->set_chat($chat);
		return ($this->telegram->send->kick($this->id, $chat) !== FALSE);
	}

	function unban($chat = NULL){
		$chat = $this->set_chat($chat);
		return ($this->telegram->send->unban($this->id, $chat) !== FALSE);
	}

	public function update($key, $value, $table = 'user', $idcol = 'telegramid'){
		// get set variables and set them to DB-table
		$query = $this->db
			->where($idcol, $this->id)
		->update($table, [$key => $value]);
		if($this->db->getLastErrno() !== 0){
			throw new Exception('Error en la consulta: ' .$this->db->getLastError());
		}
		return $query;
	}

	public function settings($key, $value = NULL){
		if($value === NULL){
			return $this->settings[$key];
		}elseif(strtoupper($value) == "DELETE"){
			return $this->settings_delete($key);
		}

		// Si los datos son array, serializar para insertar en DB.
		if(is_array($value)){
			$value = serialize($value);
		}

		if(isset($this->settings[$key])){
			$this->update($key, $value, 'settings', 'uid');
		}else{
			$data = [
				'uid' => $this->id,
				'type' => $key,
				'value' => $value,
				'hidden' => FALSE,
				'displaylist' => TRUE,
				'lastupdate' => date("Y-m-d H:i:s")
			];
			return $this->insert($data, 'settings');
		}
	}

	public function settings_delete($key){
		if(isset($this->settings[$key])){ unset($this->settings[$key]); }
		return $this->db
			->where('uid', $this->id)
			->where('type', $key)
		->delete('settings');
	}

	protected function insert($data, $table){
		return $this->db($table, $data);
	}

	protected function delete($table, $where, $value, $usercol = FALSE){
		if($usercol !== FALSE){
			$this->db->where($usercol, $this->id);
		}
		return $this->db
			->where($where, $value)
		->delete($table);
	}

	public function register($team){
		if(!empty($this->team)){ return FALSE; }
		$data = [
			'telegramid' => $this->id,
			'telegramuser' => @$this->telegram->username,
			'username' => NULL,
			'fullname' => @$this->telegram->first_name,
			'team' => $team,
			'register_date' => date("Y-m-d H:i:s"),
			'verified' => FALSE,
			'blocked' => FALSE
		];
		return $this->db->insert('user', $data);
	}

	public function load(){
		// load variables and set them here.
		$query = $this->db
			->where('telegramid', $this->id)
		->getOne('user');
		if(empty($query)){ return NULL; }
		foreach($query as $k => $v){
			$this->$k = $v;
		}

		$this->load_flags();
		$this->load_chats();
		$this->load_settings();

		$this->loaded = TRUE;
		return TRUE;
	}

	private function load_flags(){
		$this->flags = array();
		$query = $this->db
			->where('user', $this->id)
		->get('user_flags');
		if(count($query) > 0){
			$this->flags = array_column($query, 'value');
		}
		return $this->flags;
	}

	private function load_chats(){
		$this->chats = array();
		$chats = array();
		$query = $this->db
			->where('uid', $this->id)
		->get('user_inchat');
		if(count($query) > 0){
			foreach($query as $chat){
				$chatobj = $chat;
				$chatobj['id'] = $chat['cid'];
				unset($chatobj['uid']);
				$chats[$chat['cid']] = (object) $chatobj;
			}
			$this->chats = $chats;
		}
		return $this->chats;
	}

	private function load_settings(){
		$this->settings = array();
		$query = $this->db
			->where('uid', $this->id)
		->get('settings');
		if(count($query) > 0){
			$this->settings = array_column($query, 'value', 'type');
			foreach($this->settings as $k => $v){
				if($v == 1){ $this->settings[$k] = TRUE; }
				elseif($v == 0){ $this->settings[$k] = FALSE; }
			}
		}
		return $this->settings;
	}

	function in_chat($chat = NULL, $check_telegram = FALSE){
		$chat = $this->set_chat($chat);
		if($check_telegram == FALSE){
			return in_array($chat, array_keys($this->chats));
		}
	}

	function chats(){
		// return object with id, type_member, messages, register_date y last_message_date
	}

	function is_admin($chat = NULL){
		$chat = $this->set_chat($chat);
		$query = $this->db
			->where('uid', $this->id)
			->where('gid', $chat)
		->getOne('user_admins');
		return !empty($query);
	}

	function log($key, $value){
		// level -> 5 + timestamp
	}


}
