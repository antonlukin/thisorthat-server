<?php
/**
* PHP Class for providing basic service functionality
*
* @author Anton Lukin
* @license http://www.opensource.org/licenses/bsd-license.php BSD License
* @link http://lukin.me
**/


if (!class_exists('db'))
	require_once ABSPATH . $config['paths']['db'];

class Core {

	protected $_db = null;

	function __construct($config) {
		$this->_db = new DB($config['db']);
	}

	private function _normilize_array($in, $out = array()) {
		foreach($in as $k => $v) {
			$id = array_shift($v);
			$out[$id] = $v;
		}

		return $out;
	}

	private function _set_viewed($user, $items) {
		try{
			$db = $this->_db;

 			foreach($items as $id => $vote)
				$data[] = array('item' => (int)$id, 'user' => (int)$user, 'vote' => $vote);

			$query  = "INSERT IGNORE INTO view (user, item, vote) VALUES (:user, :item, :vote);";

			return $db->multiple($query, $data);
		}
		catch(DBException $e) {
			throw new CoreException($e->getMessage(), 0);
		}
	}

	private function _get_random_items($count) {
		try{
			$db = $this->_db;

			$query = "SELECT item.id, left_text, right_text, IFNULL(v.left_vote, 0) left_vote, IFNULL(v.right_vote, 0) right_vote
				FROM item
				LEFT OUTER JOIN
				(
					SELECT item, SUM(vote = 'left') left_vote, SUM(vote = 'right') right_vote
					FROM view
					GROUP BY item
				) AS v ON (v.item = id)
				ORDER BY RAND() LIMIT " . (int)$count;

			$items = $db->select($query);
		}
		catch(DBException $e) {
			throw new CoreException($e->getMessage(), 0);
		}

		return $this->_normilize_array($items);
	}

 	private function _get_user_items($user, $count) {
		try{
			$db = $this->_db;

			$query = "SELECT item.id, left_text, right_text, IFNULL(v.left_vote, 0) left_vote, IFNULL(v.right_vote, 0) right_vote
				FROM item
				LEFT OUTER JOIN
				(
					SELECT item, SUM(vote = 'left') left_vote, SUM(vote = 'right') right_vote
					FROM view
					GROUP BY item
				) AS v ON (v.item = id)
				LEFT JOIN view
				ON item.id = view.item
				WHERE item.id NOT IN
				(SELECT view.item FROM view WHERE view.user = ?)
				ORDER BY RAND() LIMIT " . (int)$count;

			$items = $db->select($query, array((int)$user));
		}
		catch(DBException $e) {
			throw new CoreException($e->getMessage(), 0);
		}

		return $this->_normilize_array($items);
	}

	private function _add_new_user($data) {
		try{
			$db = $this->_db;

			$query = "INSERT INTO user (secret, client, `unique`) VALUES (:secret, :client, :unique)";

			return $db->lastid($query, $data);
		}
		catch(DBException $e) {
			throw new CoreException($e->getMessage(), 0);
		}
	}

	private function _check_user_token($user, $secret) {
		try{
			$db = $this->_db;

			$query = "SELECT id FROM user WHERE id = ? AND secret = ? LIMIT 1";
			$count = $db->num_rows($query, array((int)$user, $secret));
		}
		catch(DBException $e) {
			throw new CoreException($e->getMessage(), 0);
		}

		return $count > 0;
	}

	private function _get_secret($token) {
		try{
			return substr(hash('sha256', $token), 10, 32);
		}
		catch(Exception $e) {
			throw new CoreException("function not found", 1);
		}
	}

	public function get_items($user = false, $count = 10) {
		if(false === $user)
			return $this->_get_random_items($count);

		return $this->_get_user_items($user, $count);
	}

	public function add_views($user, $data) {
        $valid = array('left', 'right', 'skip');

		foreach($data as $id => $vote)
			if(!in_array($vote, $valid))
				unset($data[$id]);

 		return $this->_set_viewed($user, $data);
	}

	public function authenticate($user, $token) {
		$secret = $this->_get_secret($token);

		return $this->_check_user_token($user, $secret);
	}

	public function register($client, $unique) {
		$token = md5(uniqid(rand(), true));

		$data = array (
			'secret' => $this->_get_secret($token),
			'unique' => $unique,
			'client' => $client
		);

		$user = $this->_add_new_user($data);

		return array('user' => $user, 'token' => $token);
	}

	public function attribute($list, $offset, $regex, $type = false) {
		if(!isset($list[$offset]))
			return false;

		if(true === $type && gettype($list[$offset]) !== $regex)
			return false;

		if(true === $type)
			return $list[$offset];

		if(!preg_match("/{$regex}/i", $list[$offset]))
			return false;

		return $list[$offset];
	}

	public function dataset() {
		$raw = file_get_contents("php://input");

		return json_decode($raw, true);
	}
}

class CoreException extends Exception {

	protected $message;
	protected $code;
	protected $case;

	public function __construct($message, $code = 0, Exception $previous = null) {

		$this->case = array(
			0 => "Database error: can't process ",
			1 => "Internal server error: "
		);

		$this->message = $message;
		$this->code = $code;

		parent::__construct($message, $code, $previous);

	}

	public function getDescription(){
		$code = $this->code;
		$case = $this->case;

		return isset($case[$code]) ? $case[$code] . $this->message : $this->message;
	}
}
