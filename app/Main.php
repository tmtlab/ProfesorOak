<?php

class Main extends TelegramApp\Module {
	protected $runCommands = TRUE;

	public function run(){
		if($this->telegram->left_user){ return $this->left_member(); }
		if($this->telegram->new_user){ return $this->new_member(); }

		if(
			$this->chat->load() and
			!$this->telegram->callback // Para botones inline de juegos y demás.
		){
			$this->chat->active_member($this->user->id);
		}

		if($this->chat->settings('forward_interactive')){
			$this->forward_creator();
		}

		if($this->telegram->is_chat_group()){
			if($this->telegram->data_received('migrate_to_chat_id')){
				$this->Admin->migrate_settings($this->telegram->migrate_chat, $this->chat->id);
				$this->chat->disable();
				$this->end();
			}

			$this->alarm_cheats();
			if($this->chat->settings('forwarding_to')){ $this->Admin->forward_to_groups(); }
			if($this->chat->settings('antiflood')){ $this->Admin->antiflood(); }
			if($this->chat->settings('antispam') != FALSE && $this->telegram->text_url()){ $this->Admin->antispam(); }
			if($this->chat->settings('mute_content')){ $this->Admin->mute_content(); }
			if($this->chat->settings('antiafk') and $this->telegram->key == 'message'){ $this->Admin->antiafk(); }
			if($this->chat->settings('die') && $this->user->id != CREATOR){ $this->end(); }
			if($this->chat->settings('abandon')){ $this->Group->abandon(); }
			// if($this->chat->settings('blackwords')){ $this->Admin->blackwords(); }

			// Cancelar acciones sobre comandos provenientes de mensajes de channels. STOP SPAM.
			if($this->telegram->has_forward && $this->telegram->forward_type("channel")){ $this->end(); }
		}

		// Solo puede registrarse o pedir ayuda por privado.
		if($this->user->load() !== TRUE){
			$this->hooks_newuser();
		}

		// Tras haber pasado los filtros de grupo (aparte de Admin).
		if($this->user->blocked){ $this->end(); }

		// Change language for user.
		if($this->user->settings('language')){
			$this->strings->language = $this->user->settings('language');
			$this->strings->load();
		}elseif($this->chat->settings('language')){
			$this->strings->language = $this->chat->settings('language');
			$this->strings->load();
		}

		// POST Load user in group
		if($this->telegram->is_chat_group()){
			// if($this->user->settings('mute')){ /* TODO Mute User */ }
			// if($this->chat->settings('require_avatar')){ $this->Admin->antinoavatar(); }
			if($this->chat->settings('dubs')){ $this->core->load('GameDubs', TRUE); }
			if($this->chat->settings('custom_commands')){ $this->Group->custom_commands(); }
		}

		parent::run();
	}

	public function ping(){
		return $this->telegram->send
			->text("¡Pong!")
		->send();
	}

	public function help(){
		$url = $this->strings->get('help_url');
		$this->telegram->send
			->text_replace($this->strings->get('help_text'), $url, 'HTML')
		->send();
	}

	public function lang($set = NULL){ return $this->language($set); }
	public function language($set = NULL){
		if($this->telegram->is_chat_group()){ $this->end(); }

		if($this->user->telegramid !== NULL){
			if(strlen($set) == 2 and !is_numeric($set)){
				$this->user->settings('language', $set);
				$this->telegram->send->text("Language set to <b>$set</b>!");

				if($this->telegram->callback){
					$this->telegram->send
						->chat(TRUE)
						->message(TRUE)
					->edit('text');
					$this->telegram->answer_if_callback("");
				}else{
					$this->telegram->send->send();
				}

				$this->end();
			}
		}

		$str = "You can choose the language you want me to talk." ."\n"
			."If you want to contribute or improve them, please contact @duhow. Thank you!";

		$this->telegram->send
			->inline_keyboard()
				->row()
					->button($this->telegram->emoji(':flag_es:'), "language es", "TEXT")
					->button($this->telegram->emoji(':flag_es:') . " CAT", "language ca", "TEXT")
				->end_row()
				->row()
					->button($this->telegram->emoji(':flag_us:'), "language en", "TEXT")
					->button($this->telegram->emoji(':flag_it:'), "language it", "TEXT")
				->end_row()
				/* ->row()
					->button($this->telegram->emoji(':flag_fr:'), "language fr", "TEXT")
					->button($this->telegram->emoji(':flag_de:'), "language de", "TEXT")
				->end_row() */
			->show()
			->text($str)
		->send();

		$this->end();
	}

	private function alarm_cheats(){
		if(
			$this->telegram->text_contains(["fake GPS", "fake", "fakegps", "soy fly", "voy volando", "con fly", "con el fly"]) and
			!$this->telegram->text_contains("me llamo", TRUE)
		){
			if(
				$this->user->id != CREATOR and
				!in_array($this->user->step, ["RULES", "WELCOME", "CUSTOM_COMMAND"])
			){
				$link = $this->db
					->where('uid', $this->chat->id)
					->where('type', 'link_chat')
				->getValue('settings', 'value');
				// $this->analytics->event('Telegram', 'Talk cheating');
				$str = $this->telegram->emoji(":bangbang: ")
						.'<a href="' .$this->telegram->grouplink($link, TRUE) .'">' .$this->telegram->chat->title ."</a> - "
						.$this->telegram->userlink($this->telegram->user->id, strval($this->telegram->user)) .":\n"
						.$this->telegram->text();
				$r = $this->telegram->send
					->notification(FALSE)
					->chat("-226115807")
					->disable_web_page_preview(TRUE)
					->inline_keyboard()
						->row()
							->button($this->telegram->emoji(":speaking_head_in_silhouette: Hablar"), "aspeak " .$this->chat->id, "TEXT")
							->button($this->telegram->emoji(":bangbang: Fly"), "aflag fly " .$this->telegram->user->id, "TEXT")
						->end_row()
					->show()
					->text($str, 'HTML')
				->send();
				$this->message_assign_set($r, $this->user->id);
				// $bw = $pokemon->settings($telegram->chat->id, 'blackword');
				// if(!$bw or stripos($bw, "fake") === FALSE){ return -1; }
			}
		}
	}

	public function donate(){
		// if($pokemon->command_limit("donate", $telegram->chat->id, $telegram->message, 7)){ return -1; }
		if($this->user->settings('mute')){ $this->end(); }

		if($this->chat->is_group()){
			$str = "Si quieres ayudarme, puedes contribuir con la cantidad que quieras, aunque sea un 1€. Te prometo que merecerá la pena. <3";

			$this->telegram->send
				->inline_keyboard()
				->row()
					->button("Más info", "donate", "COMMAND")
					->button("Donar", "http://donar.profoak.me/")
				->end_row()
			->show();
		}else{
			$release = strtotime("2016-07-16 14:27");
			$days = round((strtotime("now") - $release) / 3600 / 24);
			$str = implode("\n", $this->strings->get('donate_text'));

			$this->telegram->send
				->inline_keyboard()
				->row_button("Donar", "http://donar.profoak.me/")
			->show();
		}

		$str = $this->telegram->emoji($str);
		$this->telegram->send
			->text_replace($str, $days, "HTML")
		->send();

		$this->end();
	}

	public function register($team = NULL){
		$str = NULL;
		if($this->user->telegramid === NULL){
			if($team === NULL){
				$str = $this->strings->parse('register_hello_start', $this->telegram->user->first_name);
				if($this->telegram->is_chat_group()){
					$str = $this->strings->parse('register_hello_private', $this->telegram->user->first_name);
					$this->telegram->send
					->inline_keyboard()
						->row_button($this->strings->get('register'), "https://t.me/ProfesorOak_bot")
					->show();
				}
			}elseif($team === FALSE){
				$this->telegram->send->reply_to(TRUE);
				$str = $this->strings->get('error_register');
			}
			$team = trim(strtoupper($team));
			if(strlen($team) == 1 and in_array($team, ['R', 'B', 'Y'])){
				// Intentar registrar, ignorar si es anonymous.
				if($this->user->register($team) === FALSE){
					$this->telegram->send
						->text($this->strings->get('error_register'))
					->send();
					$this->end();
				}
				if($this->user->load() !== FALSE){
					$this->user->step = "SETNAME";
					if($this->user->settings('language')){
						$this->strings->language = $this->user->settings('language');
						$this->strings->load();
					}
					$str = $this->strings->parse('register_ok_name', $this->telegram->user->first_name);
				}
			}else{
				$str = $this->strings->get('register_error_color');
			}
		}elseif(!$this->user->username){
			$str = $this->strings->get('register_hello_name');
		}elseif(!$this->user->verified){
			$str = $this->telegram->emoji(":warning:") .$this->strings->get('register_hello_verify');
			$this->telegram->send
			->inline_keyboard()
				->row_button($this->strings->get('verify'), "verify", TRUE)
			->show();
		}

		if(!empty($str)){
			$this->telegram->send
				->notification(FALSE)
				->text($str, 'HTML')
			->send();
		}
		$this->end();
	}

	public function whois($username = NULL, $send = TRUE){
		if(empty($username) and $this->telegram->has_reply){
			$username = $this->message_assign_get();
			if($username == FALSE){
				$username = $this->telegram->reply_target('forward')->id;
			}
		}

		$username = $this->telegram->clean('alphanumeric', $username);
		if(strlen($username) < 4){ return NULL; }
		if(in_array($username, ["creado", "creador", "creator"])){ $username = "duhow"; } // Quien es tu creador?
		if(in_array($username, $this->strings->get('command_whois_blackword'))){ return NULL; } // Quien es quien?

		/* $pk = pokemon_parse($text);
		if(!empty($pk['pokemon'])){ /* $this->_pokedex($pk['pokemon']); *-/ return; } // TODO FIXME */

		$str = "";
		$offline = FALSE;

		$info = $this->db
			->where('(telegramid = ? OR username = ?)', [$username, $username])
			->where('anonymous', 0)
		->getOne('user');

		// si el usuario por el que se pregunta es el bot
		// HACK: $username es el UID sacad de MID Assign si hay, o su propio Reply->ID.
		if($this->telegram->has_reply and $username == $this->telegram->bot->id and !$this->telegram->reply_is_forward){
			$str = $this->strings->get('whois_bot_self');
		// si es un bot
		}elseif(strtolower(substr($username, -3)) == "bot"){
			$str = $this->strings->get('whois_is_bot'); // Yo no me hablo con los de mi especie.\nSi, queda muy raro, pero nos hicieron así...";
		// si no se conoce
		}elseif(empty($info)){
			$str = $this->strings->parse('whois_unknown_username', $username);
			// User offline
			$info = $this->db
				->where('username', $username)
			->getOne('user_offline');

			if(!empty($info)){
				$info = (object) $info; // HACK
				$offline = TRUE;
				$str = ucwords($this->strings->get('whois_user')) .' <b>$team</b> $nivel. ' .$this->telegram->emoji(':question:');
				$reps = $this->Report->get($username, TRUE);
				if(!empty($reps)){
					$reptype = array_column($reps, 'type');
					$reptype = array_unique($reptype);

					$str .= "\n" .$this->telegram->emoji(":name_badge: ")
						.$this->strings->parse('whois_user_reports', [count($reps), implode(", ", $reptype)]);
				}
				$ma = $this->Report->multiaccount_exists($username, TRUE);
				if(!empty($ma)){
					$str .= "\n" .$this->telegram->emoji(":busts_in_silhouette: ")
							.count($ma['usernames']) ." "
							.$this->strings->get('whois_multiaccount_group') .". #"
							.$ma['grouping'];
				}
			}
		}else{
			$info = (object) $info; // HACK
			if(empty($info->username)){
				$str = $this->strings->get('whois_user_noname');
			}else{
				$str = '$pokemon, ';
			}
			$str .= $this->strings->get('whois_user') .' <b>$team</b> $nivel. $valido' ."\n";

			if(!empty($info->username)){
				$reps = $this->Report->get($username, TRUE);
				if(!empty($reps)){
					$reptype = array_column($reps, 'type');
					$reptype = array_unique($reptype);

					$str .= $this->telegram->emoji(":name_badge: ")
						.$this->strings->parse('whois_user_reports', [count($reps), implode(", ", $reptype)])
						."\n";
				}
				$ma = $this->Report->multiaccount_exists($username, TRUE);
				if(!empty($ma)){
					$str .= $this->telegram->emoji(":busts_in_silhouette: ")
							.count($ma['usernames']) ." "
							.$this->strings->get('whois_multiaccount_group') .". #"
							.$ma['grouping']
							."\n";
				}
			}
		}

		if($info){
			$flags = $this->db
				->where('user', $info->telegramid)
			->getValue('user_flags', 'value', NULL);

			// añadir emoticonos basado en los flags del usuario REPETIDO
			// ----------------------
			if($info->blocked){ $str .= $this->telegram->emoji(":no_entry: "); }
			if($info->authorized){ $str .= $this->telegram->emoji(":star: "); }
			$flageq = [
				'ratkid' 		=> ":mouse:",
				'multiaccount' 	=> ":busts_in_silhouette:",
				// 'gps' 		=> ":satellite:",
				// 'bot' 		=> ":robot:",
				'fly' 			=> ":airplane:",
				'rager' 		=> ":fire:",
				'troll' 		=> ":black_joker:",
				'spam' 			=> ":incoming_envelope:",
				// 'hacks' 		=> ":computer:",
				'enlightened' 	=> ":frog:",
				'resistance' 	=> ":key2:",
				'donator' 		=> ":euro:",
				'helper' 		=> ":beginner:",
				'gay' 			=> ":rainbow_flag:",
				'unicorn'		=> ":unicorn: ",
			];

			if(!empty($flags)){
				foreach($flageq as $f => $t){
					if(in_array($f, $flags)){ $str .= $this->telegram->emoji($t ." "); }
				}
			}

			$validicon = ":white_check_mark:";

			if(!$info->verified){
				$validicon = ":warning:";
				$is_verifying = $this->db
					->where('telegramid', $info->telegramid)
					->where('status IS NULL')
				->getValue('user_verify', 'count(*)');
				if($is_verifying > 0){ $validicon .= " :clock:"; }
			}

			$repl = [
				// '$nombre'	=> $new->first_name,
				// '$apellidos'	=> $new->last_name,
				'$equipo'		=> $this->strings->get_multi('team_colors', $info->team),
				'$team'			=> $this->strings->get_multi('team_colors', $info->team),
				// '$usuario'	=> "@" .$new->username,
				'$pokemon'		=> "@" .$info->username,
				'$nivel'		=> "L" .$info->lvl,
				'$valido'		=> $validicon
			];

			$str = str_replace(array_keys($repl), array_values($repl), $str);

			if(!empty($info->username) && !$offline){
				$this->telegram->send
					->inline_keyboard()
					->row_button($this->telegram->emoji(":memo: ") .$this->strings->get('view_profile'), "http://profoak.me/user/" .$info->username)
				->show();
			}
		}

		$this->user->settings('last_command', 'WHOIS');
		if($send !== FALSE){
			$r = $this->telegram->send
				// ->chat($chat)
				// ->reply_to( (($chat == $this->telegram->chat->id && $this->telegram->has_reply) ? $this->telegram->reply->message_id : NULL) )
				->notification(FALSE)
				->text($this->telegram->emoji($str), 'HTML')
			->send();
			$target = NULL;
			if($info){ $target = $info->telegramid; }
			elseif(is_numeric($username)){ $target = $username; }

			$this->message_assign_set($r, $target);
		}
		return $str;
	}

	private function forward_creator(){
		return $this->telegram->send
			->notification(FALSE)
			->chat(TRUE)
			->message(TRUE)
			->forward_to(CREATOR)
		->send();
	}

	private function forward_groups($to){
		/* if($this->telegram->user_in_chat($this->telegram->bot->id, $chat_forward)){ // Si el Oak está en el grupo forwarding
			// $forward = new Chat($to);
			$chat_accept = explode(",", $pokemon->settings($chat_forward, 'forwarding_accept'));
			if(in_array($this->telegram->chat->id, $chat_accept)){ // Si el chat actual se acepta como forwarding...
				$this->telegram->send
					->message($this->telegram->message)
					->chat($this->telegram->chat->id)
					->forward_to($chat_forward)
				->send();
			}
		} */
	}

	private function user_mention(){
		if(
			$this->telegram->key == "edited_message" or
			$this->telegram->text_has("people voted")
		){ return NULL; } // Anti-vote

		$users = array();
		preg_match_all("/[@]\w+/", $this->telegram->text(), $users, PREG_SET_ORDER);
		foreach($users as $i => $u){ $users[$i] = substr($u[0], 1); } // Quitamos la @
		foreach($this->telegram->text_mention(TRUE) as $u){
			if(is_array($u)){ $users[] = key($u); continue; }
			if($u[0] == "@"){ $users[] = substr($u, 1); }
		}

		// Quitarse usuario a si mismo
		$self = [$this->telegram->user->id, $this->telegram->user->username];
		foreach($users as $k => $u){ if(in_array($u, $self)){ unset($users[$k]); } }

		if(empty($users)){ return NULL; }
		$users = array_unique($users);

		// ADMIN MENTION
		$admins = FALSE;
		if(in_array("admin", $users)){
			// FIXME Cambiar function get_admins por la integrada + array merge
			$admins = $this->telegram->send->get_admins();
			if(!empty($admins)){
				foreach($admins as $a){	$users[] = $a['user']->id; } // REVIEW
			}
			$admins = $this->chat->settings('admins');
			if($admins){
				$admins = explode(",", $admins);
				foreach($admins as $a){ $users[] = $a; }
			}
			$users = array_unique($users);
			$admins = TRUE;

			$adminchat = $this->chat->settings('admin_chat');
			if($adminchat){
				$this->telegram->send
					->notification(FALSE)
					->chat(TRUE)
					->message(TRUE)
					->forward_to($adminchat)
				->send();

				$this->telegram->send
					->chat($adminchat)
					->text_replace("Mensaje del usuario %s.", $this->telegram->user->id)
				->send();
			}
		}
		// -- ADMIN MENTION --

		// REVIEW not working
		/* $disabled = $this->db->subQuery();
		$disabled
			->where('type', 'no_mention')
			->where('value', 0, '!=')
		->get('settings', NULL, 'uid AS telegramid'); */

		$inchat = $this->db->subQuery();
		$inchat
			->where('cid', $this->chat->id)
		->get('user_inchat', NULL, 'uid AS telegramid');

		// Get user id numeric
		$nusers = array();
		foreach($users as $user){
			if(is_numeric($user)){ $nusers[] = (int) $user; }
		}

		// TODO REVIEW improve
		$userids = $this->db->subQuery();

		if(!empty($nusers)){ $userids->where('telegramid', $users, 'IN'); }
		$userids
			->orWhere('username', $users, 'IN')
			->orWhere('telegramuser', $users, 'IN')
			->where('anonymous', FALSE)
			->where('blocked', FALSE)
		->get('user', NULL, 'telegramid');

		$uids = $this->db
			->where('telegramid', $userids, 'IN')
			// ->where('telegramid', $disabled, 'NOT IN')
			->where('telegramid', $inchat, 'IN')
		->get('user', NULL, 'telegramid');

		if(
			empty($uids) or
			(count($uids) > 15 and $this->user->id != CREATOR)
		){ return FALSE; }

		$uids = array_column($uids, 'telegramid');

		// Preparar datos - Link del chat
		$link = $this->chat->settings('link_chat');
		$link = ($link ? $this->telegram->grouplink($link) : NULL);

		// Preparar datos - Nombre de quien escribe
		$name = (
			isset($this->telegram->user->username) ?
			"@" .$this->telegram->user->username :
			$this->telegram->userlink($this->telegram->user->id, strval($this->telegram->user))
		);

		$resfin = FALSE;
		foreach($uids as $uid){
			// Valida que el entrenador esté en el grupo
			// TODO Si es el creador / duhow, avisarle aunque no esté en el grupo.

			$str = $name ." - ";
			if(!empty($link)){ $str .= '<a href="' .$link .'">' .$this->telegram->chat->title ."</a>:\n"; }
			else{ $str .= "<b>" .$this->telegram->chat->title ."</b>:\n"; }
			$str .= $this->telegram->text();

			$res = $this->telegram->send
				->chat($uid)
				->notification(TRUE)
				->disable_web_page_preview(TRUE)
				->text($str, 'HTML')
			->send();
			if($res){
				$resfin = TRUE;
				$this->message_assign_set($res, $this->user->id);
			}
		}

		if($admins and $resfin === FALSE){
			$this->telegram->send
				->chat($this->chat->id)
				->notification(TRUE)
				->text("No puedo avisar a los @admin, no me han iniciado :(")
			->send();
		}
	}

	private function trust_user($member){
		if(!is_numeric($member)){
			$member = str_replace(['!', '@', '.', ','], '', $member);
			$uid = $this->db
				->where('username', $member)
			->getValue('user', 'telegramid');
			if(!$uid){
				$this->telegram->send
					->chat($this->user->id)
					->text(':question:')
				->send();
				return FALSE;
			}
			$member = $uid;
		}

		$data = [
			'user' => $this->user->id,
			'target' => $member
		];
		$query = $this->db
			->setQueryOption('IGNORE')
		->insert('user_trust', $data);

		$result = ($query ? ":thumbup:" : ":x:");
		$this->telegram->send
			->chat($this->user->id)
			->text($this->telegram->emoji($result))
		->send();

		// TODO: Log to chat / table.
	}

	public function new_member(){
		// $new = El que entra
		// $this->user = El que le invita (puede ser el mismo)

		$this->chat->load();
		// Cargar idioma acorde a la persona o al grupo
		if($this->user->settings('language')){
			$this->strings->language = $this->user->settings('language');
			$this->strings->load();
		}elseif($this->chat->settings('language')){
			$this->strings->language = $this->chat->settings('language');
			$this->strings->load();
		}

		$new = new User($this->telegram->new_user, $this->db);
		$adminchat = $this->chat->settings('admin_chat');

		if($new->id == $this->telegram->bot->id){

			$count = $this->telegram->send->get_members_count();
			// A excepción de que lo agregue el creador
			if($this->user->id != CREATOR){
				// Si el grupo está muerto, salir.
				if($this->chat->settings('die')){
					$this->telegram->send->leave_chat();
					$this->end();
				}

				// Si el grupo tiene <= 5 usuarios, el bot abandona el grupo
				if(is_numeric($count) && $count <= 5){
					// $this->tracking->event('Telegram', 'Join low group');
					$this->telegram->send->text("Nope.")->send();
					$this->telegram->send->leave_chat();
					$this->end();
				}

				// Si el que me agrega está registrado
				if($this->user->load()){
					if(
						$this->user->blocked or
						in_array(['hacks', 'troll', 'ratkid'], $this->user->flags)
					){
						$this->telegram->send->leave_chat();
						$this->end();
					}
				}
			}

			// Avisar al creador de que hay un grupo nuevo
			$text = ":new: ¡Grupo nuevo!\n"
					.":abc: %s\n"
					.":id: %s\n"
					.":passport_control: %s\n" // del principio de ejecución.
					.':mens: <a href="tg://user?id=%s">%s</a> - %s';
			$text = $this->telegram->emoji($text);

			$repl = [$this->chat->title,
					$this->chat->id,
					$count,
					$this->telegram->user->id, $this->telegram->user->id, $this->telegram->user->first_name];

			$r = $this->telegram->send
				->chat(CREATOR)
				->text_replace($text, $repl, 'HTML')
			->send();
			$this->message_assign_set($r, $this->telegram->user->id);

			// -----------------

			$text = $this->strings->get('welcome_bot');
			if($this->chat->messages == 0){
				$text .= "\n" .$this->strings->get('welcome_bot_newgroup');
				// TODO si el Oak es nuevo en un grupo de más de X personas,
				// Realizar investigate sólo una vez.

				// Esto se puede hacer con el count de mensajes de un grupo, si es > 0.
				// Teniendo en cuenta que el grupo no se borre de la DB para que
				// no vuelva a ejecutarse este método.
			}

			$this->telegram->send
				->text($text, 'HTML')
			->send();
			$this->end();
		}

		// Si entra el creador
		if($new->id == CREATOR){
			if($new->settings('silent_join')){ $this->end(); }
			$r = $this->telegram->send
				->notification(TRUE)
				->reply_to(TRUE)
				->text($this->strings->get('welcome_group_creator'))
			->send();
			$this->message_assign_set($r, $new->id);
			$this->end();
		}

		// Si el grupo no admite más usuarios...
		if(
			$this->chat->settings('limit_join') == TRUE &&
			!$this->chat->is_admin($this->user) // Si el que lo agrega no es Admin
		){
			// $this->tracking->event('Telegram', 'Join limit users');
			$this->Admin->kick($new->id);
			$r = $this->Admin->admin_chat_message($this->strings->parse('adminchat_newuser_limit_join', $new->id));
			$this->message_assign_set($r, $new->id);
			// $pokemon->user_delgroup($new->id, $this->telegram->chat->id);
			$this->end();
		}

		// Bot agregado al grupo. Vamos a ver si se puede quedar.
		if($new->id != $this->telegram->bot->id && $this->telegram->is_bot($new->username)){
			if(
				in_array($this->telegram->user->id, telegram_admins(TRUE)) or // Lo agrega un admin, no pasa na.
				!$this->chat->settings('mute_content') // No hay límites.
			){ $this->end(); }

			$mute = explode(",", $this->chat->settings('mute_content'));
			if(!in_array("bot", $mute)){ $this->end(); } // Se permite agregar bots

			$this->telegram->send->ban($this->telegram->user->id);
			$this->telegram->send->ban($new->id);

			// ---------
			$str = ":warning: " .$this->strings->get('adminchat_newuser_add_bot') ."\n"
					.":id: @" .$new->username ." - " .$new->id ."\n"
					.":mens: " .$this->telegram->user->first_name ." - " . $this->telegram->user->id;

			$str = $this->telegram->emoji($str);
			// ---------
			$r = $this->Admin->admin_chat_message($str);
			$this->message_assign_set($r, $new->id);
			$this->end();
		}

		// Cargar información del usuario si está registrado.
		$new->load();

		if($new->settings('follow_join')){
			$str = ":warning: Join detectado\n"
					.":id: " .$new->id ." - " .$this->telegram->new_user->first_name ."\n"
					.":multiuser: " .$this->telegram->chat->id ." - " .$this->telegram->chat->title;
			$str = $this->telegram->emoji($str);
			$r = $this->telegram->send
				->notification(TRUE)
				->chat(CREATOR)
				->text($str)
			->send();
			$this->message_assign_set($r, $new->id);
		}

		if($this->chat->settings('team_exclusive')){
			// Si el grupo es exclusivo a un color y el usuario es de otro color
			if($this->chat->settings('team_exclusive') != $new->team){
				$this->telegram->send
					->notification(TRUE)
					->reply_to(TRUE)
					->text_replace($this->strings->get('welcome_group_team_exclusive'), $new->username, 'HTML')
				->send();

				// Kickear (por defecto TRUE)
				if(
					$this->chat->settings('team_exclusive_kick') != FALSE &&
					!$this->chat->is_admin($this->user) // Si NO es admin el que lo mete
				){
					$q = $this->Admin->kick($new->id);
					if($q !== FALSE){
						$str = ":times: " .$this->strings->get('adminchat_newuser_team_exclusive_invalid') ."\n"
								.":id: " .$new->id ."\n"
								.":abc: " .$this->telegram->new_user->first_name ." - @" .$new->username;
						$str = $this->telegram->emoji($str);
						$r = $this->Admin->admin_chat_message($str);
						$this->message_assign_set($r, $new->id);
					}
				}

				$this->end();
			}
		}

		if(
			$this->chat->settings('blacklist') &&
			!$this->chat->is_admin($this->user) && // El que invita no es admin
			$new->flags // Tiene flags / blacklist?
		){
			$blacklist = explode(",", $this->chat->settings('blacklist'));
			foreach($blacklist as $b){
				if(in_array($b, $new->flags)){
					// $this->tracking->event('Telegram', 'Join blacklist user', $b);
					$q = $this->Admin->kick($new->id);

					$str = ":times: " .$this->strings->get('adminchat_newuser_in_blacklist') ." - $b\n"
					.":id: " .$new->id ."\n"
					.":abc: " .$this->telegram->new_user->first_name ." - @" .$new->username;
					$str = $this->telegram->emoji($str);

					$r = $this->Admin->admin_chat_message($str);
					$this->message_assign_set($r, $new->id);
					// $pokemon->user_delgroup($new->id, $this->telegram->chat->id);
					$this->end();
				}
			}
		}

		// Si el grupo requiere validados
		if(
			$this->chat->settings('require_verified') and
			$this->chat->settings('require_verified_kick') and
			!$new->verified
		){
			// $this->tracking->event('Telegram', 'Kick unverified user');
			$str = $this->strings->get('user') ." " .$this->telegram->new_user->first_name ." / " .$new->id ." ";

			if(!$this->chat->is_admin($this->user)){
				$q = $this->Admin->kick($new->id);
				if($q !== FALSE){
					// $pokemon->user_delgroup($new->id, $this->telegram->chat->id);
					$str = $this->strings->get('admin_kicked_unverified');

					$str2 = ":warning: " .$this->strings->get('adminchat_newuser_not_verified') ."\n"
							.":id: " .$new->id ."\n"
							.":abc: " .$this->telegram->new_user->first_name ." - @" .$new->username;
					$str2 = $this->telegram->emoji($str2);
					$r = $this->Admin->admin_chat_message($str2);
					$this->message_assign_set($r, $new->id);
				}
			}else{
				$str = $this->strings->get('welcome_group_unverified');
			}

			$this->telegram->send
				->text($str)
			->send();
			$this->end();
		}

		// Si un usuario generico se une al grupo
		if($this->chat->settings('announce_welcome') !== FALSE){
			$custom = $this->chat->settings('welcome');
			$text = $this->strings->parse('welcome_group', $this->telegram->new_user->first_name) ."\n";
			if(!empty($custom)){ $text = json_decode($custom) ."\n"; }
			if($new->team === NULL){
				$text .= $this->strings->get('welcome_group_register');
			}else{
				$text .= '$pokemon $nivel $equipo $valido $ingress';
				$required = array();

				if(!$new->verified && $this->chat->settings('require_verified')){
					$required[] = $this->strings->get('welcome_group_require_verified');

					$this->telegram->send
						->inline_keyboard()
							->row_button($this->strings->get("verify"), "verify", "COMMAND")
						->show();
				}

				if(!$new->telegram->username and $this->chat->settings('require_alias')){
					$required[] = $this->strings->get('welcome_group_require_alias');
				}

				if(!empty($required)){
					$text .= "\n" .$this->strings->get('welcome_group_require_start') ."<b>"
								.implode(", ", $required) ."</b>.";
				}
			}

			// $pokemon->user_addgroup($new->id, $this->telegram->chat->id);
			// $this->tracking->event('Telegram', 'Join user');

			$ingress = NULL;
			if($new->flags and in_array('resistance', $new->flags)){ $ingress = ":key:"; }
			elseif($new->flags and in_array('enlightened', $new->flags)){ $ingress = ":frog:"; }

			$pokename = (strlen($new->username) > 1 ? "@" .$new->username : "");
			$lvl = ($new->lvl > 1 ? "L" .$new->lvl : "");
			if(empty($pokename) and empty($lvl) and !$new->verified){
				$pokename = $this->strings->get('register_hello_name');
			}

			$emoji = ["Y" => "yellow", "B" => "blue", "R" => "red"];
			$repl = [
				'$nombre' => $new->telegram->first_name,
				'$apellidos' => $new->telegram->last_name,
				'$equipo' => ':heart-' .$emoji[$new->team] .':',
				'$team' => ':heart-' .$emoji[$new->team] .':',
				'$usuario' => "@" .$new->telegram->username,
				'$pokemon' => $pokename,
				'$nivel' => $lvl,
				'$valido' => $new->verified ? ':white_check_mark:' : ':warning:',
				'$ingress' => $ingress
			];
			$text = str_replace(array_keys($repl), array_values($repl), $text);
			$text = $this->telegram->emoji($text);
			$r = $this->telegram->send
				->notification(FALSE)
				->reply_to(TRUE)
				->text( $text , 'HTML')
			->send();
			$this->message_assign_set($r, $new->id);
		}

		// Avisar al grupo administrativo
		$str = ":new: " .$this->strings->get('adminchat_newuser_enter') ."\n"
				.":id: " .$new->id ."\n"
				.":abc: " .$this->telegram->new_user->first_name ." - @" .$new->username;
		$str = $this->telegram->emoji($str);
		$r = $this->Admin->admin_chat_message($str);
		$this->message_assign_set($r, $new->id);
	}

	public function left_member(){
		$this->chat->load();
		if($this->telegram->left_user->id == $this->telegram->bot->id){
			$str = ":door: Me han echado :(\n"
					.":id: " .$this->telegram->chat->id ."\n"
					.":abc: " .$this->telegram->chat->title ."\n"
					.":mens: " .'<a href="tg://user?id=' .$this->telegram->user->id .'">' .$this->telegram->user->id . "</a> - " . $this->telegram->user->first_name;
			$str = $this->telegram->emoji($str);

			$this->db
				->where('chat', $this->telegram->chat->id)
			->delete('poleauth');

			$r = $this->telegram->send
				->notification(TRUE)
				->chat(CREATOR)
				->text($str, 'HTML')
			->send();
			$this->message_assign_set($r, $this->telegram->user->id);

			$this->chat->disable();
		}else{
			// TODO not delete, disable and record new entrances.
			// TODO update latest record order by date limit 1
			$this->db
				->where('uid', $this->telegram->user->id)
				->where('cid', $this->telegram->chat->id)
			->delete('user_inchat');

			// TODO Admin message user left for > 50 members group.
		}
		$this->end();
	}

	protected function hooks(){
		// iniciar variables
		// $pokemon = $this->pokemon;

		// Cancelar pasos en general.
		if(
			$this->user->step != NULL and
			$this->telegram->text_has($this->strings->get('cancel'), TRUE) //  and
			// $this->telegram->words() <= 2
		){
			$this->user->step = NULL;
			// $this->user->update();
			$this->telegram->send
				->notification(FALSE)
				->keyboard()->selective(FALSE)->hide()
				->text($this->strings->get('step_cancel'))
			->send();
			$this->end();
		}

		if($this->telegram->text_command("register")){ return $this->register(); }
		// Registro nombre
		if(
			!$this->telegram->text_command() and
			(
				$this->user->step == "SETNAME" and $this->telegram->words() == 1
			) or (
				$this->telegram->text_regex($this->strings->get('command_register_username')) and
				in_array($this->telegram->words(), [3,4,5,6]) // HACK
			)
		){
			$username = $this->telegram->input->name;
			if($this->telegram->words() == 1){
				$username = $this->telegram->last_word();
			}
			$this->set_username($username);
			$this->end();
		}

		// LEVELUP
		if(
			(
				$this->telegram->words() <= $this->strings->get('command_levelup_limit') and
				$this->telegram->text_regex($this->strings->get("command_levelup"))
			) or (
				$this->user->step == "CHANGE_LEVEL" and
				$this->telegram->has_reply and
				!$this->telegram->has_forward and
				$this->telegram->reply_user->id == $this->telegram->bot->id and
				$this->telegram->words() <= 4 and
				$this->telegram->text_regex("{N:level}") // CHECK
			)
		){
			$this->levelup($this->telegram->input->level);
		}

		// LANGUAGE
		if($this->telegram->callback and $this->telegram->text_regex("language {lang}")){
			return $this->language($this->telegram->input->lang);
		}

		// WHOIS
		if(
			(
				$this->telegram->words() <= $this->strings->get('command_whois_limit') and
				$this->telegram->text_regex($this->strings->get('command_whois'))
			) or (
				$this->telegram->has_reply and
				$this->telegram->words() <= 3 and
				$this->telegram->text_regex($this->strings->get('command_whois_reply'))
			)
		){
			$username = (isset($this->telegram->input->username) ? $this->telegram->input->username : NULL);
			return $this->whois($username);
		}

		if(
			$this->telegram->text_regex($this->strings->get('command_user_trust')) and
			$this->telegram->words() <= $this->strings->get('command_user_trust_limit')
		){
			$target = NULL;
			if($this->telegram->has_reply){
				$target = $this->telegram->reply_target('forward')->id;
			}elseif($this->telegram->input->target){
				$target = $this->telegram->input->target;
			}
			if(empty($target)){ $this->end(); }
			return $this->trust_user($target);
		}

		if(
			$this->telegram->text_regex($this->strings->get('command_pokemongo_update')) and
			$this->telegram->words() <= $this->strings->get('command_pokemongo_update_limit')
		){
			return $this->announce_pokemon_update();
		}elseif(
			$this->telegram->text_regex($this->strings->get('command_pokemongo_status')) and
			$this->telegram->words() <= $this->strings->get('command_pokemongo_status_limit')
		){
			return $this->announce_pokemon_status();
		}

		if(
			$this->telegram->text_mention() and
			$this->chat->is_group() and
			!$this->chat->settings('no_mention') and
			$this->user->step === NULL
		){
			$this->user_mention();
		}

		// LOAD
		foreach($this->core->getLoaded() as $name){
			if(in_array($name, ["Main", "User", "Chat"])){ continue; }
			$this->core->load($name, TRUE);
		}
	}

	private function set_username($name = NULL){
		if(empty($name) or strlen($name) < 4){ $this->end(); }

		$this->user->step = NULL;
		$res = $this->user->register_username($name, FALSE);
		if($res === TRUE){
			$this->tracking->track('Register username');
			$this->telegram->send
				->inline_keyboard()
					->row_button($this->strings->get('verify'), "verify", TRUE)
				->show()
				->reply_to(TRUE)
				->notification(FALSE)
				->text($this->strings->parse("register_successful", $name), "HTML")
			->send();
		}elseif($res === FALSE){
			$this->telegram->send
				->reply_to(TRUE)
				->notification(FALSE)
				->text($this->strings->parse("register_error_duplicated_name", $name), "HTML")
			->send();
		}elseif($res == -1){
			// Name already set.
			$this->end();
		}elseif($res == -2){
			$this->telegram->send
				->reply_to(TRUE)
				->notification(FALSE)
				->text($this->strings->get("register_error_name_shln"), "HTML")
			->send();
		}
	}

	private function levelup($level = NULL){
		if(empty($level) or !is_numeric($level)){
			$this->end();
		}

		if($level == $this->user->lvl){
			$this->telegram->send
				->notification(FALSE)
				->text($this->strings->get('levelup_same'))
			->send();
			$this->end();
		}

		$this->tracking->track("Change level $level");
		if($this->user->step == "CHANGE_LEVEL"){
			$this->user->step = NULL;
		}
		$this->user->settings('last_command', 'LEVELUP');
		if($level >= 5 && $level <= 35){
			if($level < $this->user->lvl){ $this->end(); } // No volver atrás.
			$old = $this->user->lvl;
			$this->user->lvl = $level;
			$this->user->exp = 0;
			// $pokemon->log($this->telegram->user->id, 'levelup', $level);

			// Vale. Como me vuelvas a preguntar quien eres, te mando a la mierda. Que lo sepas.
			$str = $this->strings->parse("levelup_ok_2", $level);

			if($this->user->step == "SCREENSHOT_VERIFY" and $old == 1){
				$str = $this->strings->get("levelup_verify");
			}

			$this->telegram->send
				->text($str, 'HTML')
				->notification(FALSE)
			->send();
		}elseif(
			($level > 35 and $level <= 40) and
			$level > $this->user->lvl // No volver atrás.
		){
			if($this->user->lvl == 1){
				$this->user->lvl = $level;
				$this->user->exp = 0;

				$str = 'levelup_first_too_much';
			}elseif($level > $this->user->lvl + 1){
				$str = 'levelup_too_much';
			}else{
				$this->user->step = "LEVEL_SCREENSHOT";
				$str = 'levelup_screenshot';
			}

			$this->telegram->send
				->text($this->strings->get($str))
			->send();
		}
	}

	private function announce_pokemon_update(){
		$this->telegram->send
			->chat(TRUE)
			->chat_action('typing')
		->send();

		$apple = Tools::AppInfoApple("pokemon-go", 1094591345);
		$robot = Tools::AppInfoGoogle('com.nianticlabs.pokemongo');

		$str = "";

		foreach(['apple', 'robot'] as $os){
			$str .= ":$os: ";
			if(${$os}['days'] == 1){
				$str .= $this->strings->get('pokemongo_update_new_today');
			}elseif(${$os}['days'] == 2){
				$str .= $this->strings->get('pokemongo_update_new_yesterday');
			}else{
				$str .= $this->strings->parse('pokemongo_update_new_ago', ${$os}['days']);
			}

			$str .= ' (' .${$os}['version'] .')' ."\n";
		}

		return $this->telegram->send
			->inline_keyboard()
				->row()
					->button("Android", "https://play.google.com/store/apps/details?id=com.nianticlabs.pokemongo")
					->button("Apple", "https://itunes.apple.com/es/app/pokemon-go/id1094591345")
				->end_row()
			->show()
			->text($str)
		->send();
	}

	private function announce_pokemon_status(){
		$this->telegram->send
			->chat(TRUE)
			->chat_action('typing')
		->send();

		$game = Tools::PokemonGoStatusGame();
		$ptc  = Tools::PokemonGoStatusPTC();

		$selcode = (string) (int) $game . (string) (int) $ptc;

		$icon = ':white_check_mark:'; // 11
		if(!$game and !$ptc){ $icon = ':bangbang:'; } // 00
		elseif(!$game or !$ptc){ $icon = ':warning:'; } // 10/01

		$str = $icon .' ' .$this->strings->get('pokemongo_status_' .$selcode);
		if(!$game or !$ptc){
			$str .= "\n" .'(' .$this->strings->get_random('pokemongo_status_joke') .')';
		}

		return $this->telegram->send
			->notification(TRUE)
			->text($str, 'HTML')
		->send();
	}

	private function autoconfigure($type, $chat = NULL){
		if(empty($chat)){ $chat = $this->chat->id; }
		$chat = new Chat($chat, $this->db);
	}

	public function message_assign_set($mid, $chat = NULL, $user = NULL){
		if(is_array($mid)){
			if(empty($user) and !empty($chat)){
				$user = $chat;
				$chat = $mid['chat']['id'];
				$mid = $mid['message_id'];
			}elseif(empty($chat) and empty($user)){
				$user = $mid['from']['id'];
				$chat = $mid['chat']['id'];
				$mid = $mid['message_id'];
			}
		}
		if(!$mid){ return FALSE; }

		$data = [
			'mid' => $mid,
			'cid' => $chat,
			'target' => $user,
			'date' => $this->db->now(),
		];

		$id = $this->db->insert('user_message_id', $data);
		// TODO Cache
		// $key = 'message_assign_' .md5($mid .$chat);
		// $this->cache->save($key, $user, 3600*24);
		return $id;
	}

	public function message_assign_get($mid = NULL, $chat = NULL){
		// mirar si hay reply y llamar directamente
		if(empty($mid)){
			if(!$this->telegram->has_reply){ return FALSE; }
			$mid = $this->telegram->reply->message_id;
			$chat = $this->chat->id;
		}

		if($chat instanceof Chat){ $chat = $chat->id; }
		// TODO Cache
		// $key = 'message_assign_' .md5($mid .$chat);
		// $cache = $this->cache->get($key);
		// if($cache){ return $cache; }
		$uid = $this->db
			->where('mid', $mid)
			->where('cid', $chat)
		->getValue('user_message_id', 'target');
		return $uid;
	}

	private function hooks_newuser(){
		// TODO HACK WIP Get all translations
		$langs = ['es', 'en', 'ca', 'it'];
		$langsel = NULL;
		$teams = ['B' => 'mystic', 'R' => 'valor', 'Y' => 'instinct'];
		$color = NULL;

		foreach($langs as $lang){
			// -------------
			// Buscar si sólo ha dicho el color
			foreach($teams as $code => $team){
				if($this->telegram->text_has($this->strings->get("team_{$team}_color", $lang), TRUE)){
					$color = $code;
					$langsel = $lang;
					break;
				}
			}
			if(strlen($color) == 1){ break; }
			// -------------
			// Soy color/equipo ...
			$reg = $this->telegram->text_regex($this->strings->get('command_register_color', $lang));
			if(!$reg){ continue; } // or !$this->telegram->input->color

			$color = strtolower($this->telegram->input->color);
			$langsel = $lang;
			foreach($teams as $code => $team){
				if(
					($color == strtolower($this->strings->get("team_$team", $lang))) or
					(in_array($color, $this->strings->get("team_{$team}_color", $lang)))
				){
					$color = $code;
					break;
				}
			}
			if(strlen($color) == 1){ break; }
		}

		if(!empty($langsel)){
			$this->user->settings('language', $langsel);
			$this->strings->language = $langsel;
			$this->strings->load();
		}

		// Registrar con frase
		if(!empty($color)){
			$this->register($color);
		}elseif(
			$this->telegram->text_command("register") or
			($this->telegram->text_command("start") and
			!$this->telegram->is_chat_group())
		){
			$this->register(NULL);
		}elseif(
			$this->telegram->text_command("help") and
			!$this->telegram->is_chat_group()
		){
			$this->help();
		}elseif(
			$this->telegram->text_command("start") and
			!$this->chat->is_group()
		){
			$this->telegram->send
				->text($this->strings->parse('register_hello_start', $this->telegram->user->first_name))
			->send();
		}elseif(
			$this->telegram->text_command("start") and
			$this->chat->is_group()
		){
			// TODO FUTURE: Start group command for join groups w/ user not registered.
		}
		$this->end();
	}

}
