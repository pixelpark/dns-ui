<?php
##
## Copyright 2013-2017 Opera Software AS
##
## Licensed under the Apache License, Version 2.0 (the "License");
## you may not use this file except in compliance with the License.
## You may obtain a copy of the License at
##
## http://www.apache.org/licenses/LICENSE-2.0
##
## Unless required by applicable law or agreed to in writing, software
## distributed under the License is distributed on an "AS IS" BASIS,
## WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
## See the License for the specific language governing permissions and
## limitations under the License.
##

class UserDirectory extends DBDirectory {
	private $ldap;
	private $cache_uid;

	public function __construct() {
		parent::__construct();
		global $ldap;
		$this->ldap = $ldap;
		$this->cache_uid = array();
	}

	public function add_user(User $user) {
		$stmt = $this->database->prepare('INSERT INTO "user" (uid, name, email, active, admin, auth_realm) VALUES (?, ?, ?, ?, ?, ?)');
		$stmt->bindParam(1, $user->uid, PDO::PARAM_INT);
		$stmt->bindParam(2, $user->name, PDO::PARAM_STR);
		$stmt->bindParam(3, $user->email, PDO::PARAM_STR);
		$stmt->bindParam(4, $user->active, PDO::PARAM_INT);
		$stmt->bindParam(5, $user->admin, PDO::PARAM_INT);
		$stmt->bindParam(6, $user->auth_realm, PDO::PARAM_INT);
		$stmt->execute();
		$user->id = $this->database->lastInsertId('user_id_seq');
	}

	public function get_user_by_id($id) {
		$stmt = $this->database->prepare('SELECT * FROM "user" WHERE id = ?');
		$stmt->bindParam(1, $id, PDO::PARAM_INT);
		$stmt->execute();
		if($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$user = new User($row['id'], $row);
		} else {
			throw new UserNotFoundException('User does not exist.');
		}
		return $user;
	}

	public function get_user_by_uid($uid) {
		if(isset($this->cache_uid[$uid])) {
			return $this->cache_uid[$uid];
		}
		$stmt = $this->database->prepare('SELECT * FROM "user" WHERE uid = ?');
		$stmt->bindParam(1, $uid, PDO::PARAM_STR);
		$stmt->execute();
		if($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$user = new User($row['id'], $row);
			$this->cache_uid[$uid] = $user;
		} else {
			$user = new User;
			$user->uid = $uid;
			$this->cache_uid[$uid] = $user;
			$user->get_details_from_ldap();
			$this->add_user($user);
		}
		return $user;
	}

	public function list_users($include = array(), $filter = array()) {
		// WARNING: The search query is not parameterized - be sure to properly escape all input
		$fields = array('"user".*');
		$joins = array();
		$where = array();
		foreach($filter as $field => $value) {
			if($value) {
				switch($field) {
				case 'uid':
					$where[] = "uid REGEXP ".$this->database->quote($value);
					break;
				}
			}
		}
		$stmt = $this->database->prepare('
			SELECT '.implode(', ', $fields).'
			FROM "user" '.implode(" ", $joins).'
			'.(count($where) == 0 ? '' : 'WHERE ('.implode(') AND (', $where).')').'
			GROUP BY "user".id
			ORDER BY "user".uid
		');
		$stmt->execute();
		$users = array();
		while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$users[] = new User($row['id'], $row);
		}
		return $users;
	}
}

class UserNotFoundException extends Exception {}