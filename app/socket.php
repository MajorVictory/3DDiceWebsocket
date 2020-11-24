<?php

namespace Dice;
define('DIR_ROOT', './');

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

ini_set('date.timezone', 'America/Chicago');

class Room {

    public $id;
    public $name;
    public $owner;
    public $players;

    // room passwords are plaintext, stored in memory, and not designed to be secure in any way
    // do not use important passwords for an online dice rolling room
    public $password; 

    public $settings;
    public $created;
    public $lastactivity;

    public function __construct($RoomName, $Password = '') {
        $this->name = $RoomName;
        $this->password = $Password;
        $this->id = md5(time()+'-'+$RoomName);
        $this->players = new \SplObjectStorage;
        $this->created = time();
        $this->lastactivity = time();
        // not enforced yet
        $this->settings = array(
            'maxDice' => 50,
            'allowOwnerFudge' => 1
        );
    }

    public function sendAll($Message) {
        //$this->log('Sending to room '.$this->name.': '.$Message);

        if(!$this->players || $this->players->count() < 1) {
            $this->lastactivity = time();
            return;
        }

        $this->players->rewind();
        while($this->players->valid()) {
            $this->players->current()->connection->send($Message);
            $this->players->next();
        }
        $this->lastactivity = time();
    }

    public function addPlayer(Player $player) {

        if(!$this->players) $this->players = new \SplObjectStorage;

        $this->players->attach($player);
        $player->room = $this;
        $this->lastactivity = time();
    }

    public function removePlayer(Player $player) {

        if(!$this->players || $this->players->count() < 1) {
            $player->room = null;
            $this->lastactivity = time();
            return;
        }

        $this->players->detach($player);
        $player->room = null;

        // owner removed, try to pick a new owner, just grab first in the list
        if ($this->isOwner($player)) {
            $this->players->rewind();
            while($this->players->valid()) {
                $this->setOwner($this->players->current());
                break;
            }
        }
        $this->lastactivity = time();
    }

    public function getRoomData() {
        return $RoomData = array(
            'tid' => $this->id,
            'list' => $this->playerList(),
            'room' => $this->name
        );
    }

    public function setOwner(Player $player) {
        $this->owner = $player;
        $this->lastactivity = time();
    }

    public function isOwner(Player $player) {
        return $this->owner->connection == $player->connection;
    }

    //returns a simple array of player names only
    public function playerList() {

        if(!$this->players || $this->players->count() < 1) {
            return array();
        }

        $playerlist = array();

        $this->players->rewind();
        while($this->players->valid()) {

            $ownersymbol = $this->isOwner($this->players->current()) ? '👑 ': '';

            array_push($playerlist, $ownersymbol.$this->players->current()->name);
            $this->players->next();
        }

        return $playerlist;
    }
}

class Player {

    public $connection;
    public $room;
    public $name;
    public $colorset;
    public $texture;
    public $material;

    public function __construct(ConnectionInterface $connection) {
        $this->connection = $connection;
        $this->colorset = '';
        $this->texture = '';
        $this->material = '';
    }
}


class Socket implements MessageComponentInterface {

    private $clients;
    private $games;

    public function __construct() {
        $this->log("Starting ...");
        $this->clients = new \SplObjectStorage;
        $this->games = new \SplObjectStorage;
        $this->log(" ... Ready.");
    }

    public function getRoom($id, $pass = '') {

        $this->games->rewind();
        while($this->games->valid()) {

            $room = $this->games->current();

            if ($room->id == $id || $room->name == $id) {
                if ($room->password == '' || ($room->password != '' && $room->password == $pass)) {
                    return $room;
                }
            }
            $this->games->next();
        }
        return null;
    }

    public function getPlayerRoom(ConnectionInterface $conn) {

        $player = $this->getPlayer($conn);
        if ($player == null) return null;

        $this->games->rewind();
        while($this->games->valid()) {
            if ($this->games->current()->players->contains($player)) return $this->games->current();
            $this->games->next();
        }
        return null;
    }

    public function getPlayer(ConnectionInterface $conn) {
        $this->clients->rewind();
        while($this->clients->valid()) {
            if ($this->clients->current()->resourceId == $conn->resourceId) return $this->clients->getInfo();
            $this->clients->next();
        }
        return null;
    }

    // 
    public function onOpen(ConnectionInterface $conn) {

        // Store the new connection in $this->clients
        $this->clients->attach($conn, new Player($conn));
        
        $conn->send(json_encode(array('cid' => $conn->resourceId)));

        $this->log("{$conn->resourceId}: Connected");
        $this->log('Clients: '.$this->clients->count());
    }

    public function onMessage(ConnectionInterface $from, $msg) {

        $response = array();

        $data = json_decode($msg);
        $this->log($from->resourceId.': '.$msg);

        if (!$data || count($data) < 0 || strlen($data->method) < 0) {
            $this->log('Invalid data: '.print_r($data,true));
            return;
        }

        //$this->log($from->resourceId.' -> '.print_r($data,true));

        $player = $this->getPlayer($from);
        $gameroom = $this->getPlayerRoom($from);

        if ($data->method == 'join') {

            $original_pass = $data->pass;

            $data->user = substr(trim(preg_replace( "/[^ a-zA-Z0-9_\.-]/", "", $data->user )), 0, 35);
            $data->room = substr(trim(preg_replace( "/[^ a-zA-Z0-9_\.-]/", "", $data->room )), 0, 35);
            $data->pass = substr(trim(preg_replace( "/[^ a-zA-Z0-9_\.-]/", "", $data->pass )), 0, 100);

            if (strlen($data->user) < 1) {
                $player->connection->send(json_encode(array('error' => 'Player Name Invalid (spaces, a-Z, 0-9, \'.\', \'_\', \'-\', 35 chars max)')));
                $this->log("{$from->resourceId}: Player Name Error");
                return;
            }

            if (strlen($data->room) < 1) {
                $player->connection->send(json_encode(array('error' => 'Room Name Invalid (spaces, a-Z, 0-9, \'.\', \'_\', \'-\', 35 chars max)')));
                $this->log("{$from->resourceId}: Room Name Error");
                return;
            } 

            if ($data->pass != $original_pass) {
                $player->connection->send(json_encode(array('error' => 'Password Invalid (spaces, a-Z, 0-9, \'.\', \'_\', \'-\', 100 chars max)')));
                $this->log("{$from->resourceId}: Password Error");
                return;
            } 

            $player->name = $data->user;

            $gameroom = $this->getRoom($data->room, $data->pass);
            $roomaction = 'join';

            // create new room
            if ($gameroom == null) {
                $gameroom = new Room($data->room, $data->pass);
                $gameroom->setOwner($player);
                $this->games->attach($gameroom);
                $roomaction = 'create';
            } else {
                if ($gameroom->password != '' && $gameroom->password != $data->pass) {
                    $player->connection->send(json_encode(array('error' => 'Room Password Incorrect')));
                    $this->log("{$from->resourceId}: Room Password Error");
                    return;
                } 
            }

            $gameroom->addPlayer($player);

            $player->connection->send(json_encode(array('action' => 'login', 'method' => 'join', 'user' => $player->name)));
            // provide small delay for client js to acknowledge and act
            // possible race condition if server needs to communicate and someone logs in at the same time.
            sleep(1);

            $roomdata = $gameroom->getRoomData();
            $roomdata['action'] = 'userlist';
            $roomdata['act'] = 'add';
            $roomdata['user'] = $player->name;

            if ($gameroom) $gameroom->sendAll(json_encode($roomdata));

            $this->clients->attach($from, $player); //update player object
        } else if ($data->method == 'logout') {

            if($gameroom) {
                $gameroom->removePlayer($player);

                if ($gameroom->players->count() > 0) {
                
                    $roomdata = $gameroom->getRoomData();
                    $roomdata['action'] = 'userlist';
                    $roomdata['act'] = 'del';
                    $roomdata['user'] = $player->name;

                    if ($gameroom) $gameroom->sendAll(json_encode($roomdata));

                } else {
                    $this->games->detach($gameroom);
                }
            }
        } else if ($data->method == 'option') {

            if($gameroom && $gameroom->isOwner($player)) {


                // check for valid option key name
                if (!in_array($data->key, array_keys($gameroom->settings))) {
                    $player->connection->send(json_encode(array('error' => 'Invalid option key.')));
                    $this->log("{$from->resourceId}: Invalid option key.");
                    return;
                }

                $newValue = $gameroom->settings[$data->key];
                $oldValue = $gameroom->settings[$data->key];

                if ($data->key == 'maxDice') { //not currently enforced

                    $newValue = abs($data->value);
                    if ($oldValue == $newValue) return;

                    // check for valid option key name
                    if (!is_numeric($newValue) || $newValue <= 0) {
                        $player->connection->send(json_encode(array('error' => 'Value must be numeric and > 0.')));
                        $this->log("{$from->resourceId}: Value must be numeric and > 0.");
                        return;
                    }

                    $gameroom->settings[$data->key] = $newValue;

                } else if ($data->key == 'allowOwnerFudge') { //not currently enforced

                    $newValue = abs($data->value) == 1;
                    if ($oldValue == $newValue) return;

                    // check for valid option key name
                    if (!is_numeric($newValue) || $newValue <= 0) {
                        $player->connection->send(json_encode(array('error' => 'Value must be numeric 0 or 1.')));
                        $this->log("{$from->resourceId}: Value must be numeric 0 or 1.");
                        return;
                    }

                    $gameroom->settings[$data->key] = $newValue;
                }

                $gameroom->owner->connection->send(json_encode(array('message' => "Room option changed [{$data->key}]: {$oldValue} -> {$newValue}")));
                $this->log("{$from->resourceId}: Room option changed [{$data->key}]: {$oldValue} -> {$newValue}");
                return;

            } else {

                $gameroom->owner->connection->send(json_encode(array('warning' => 'Player tried to change room option.')));
                $player->connection->send(json_encode(array('error' => 'Only room owner can change room options.')));
                $this->log("{$from->resourceId}: Tried to change room option when not owner.");
                return;

            }
        } else if ($data->method == 'chat') {

            $gameroom->players->rewind();
            while($gameroom->players->valid()) {

                // all other players get full chat message
                if ($gameroom->players->current()->connection->resourceId != $from->resourceId) {

                    $response = array(
                        'action' => 'chat',
                        'user' => $player->name,
                        'text' => $data->text,
                        'time' => $data->time
                    );

                    $gameroom->players->current()->connection->send(json_encode($response));
                } else { // sending player gets a uuid for confirmation and a chat action

                    $response = array();
                    $response['action'] = 'chat';
                    $response['uuid'] = $data->uuid;
                    $gameroom->players->current()->connection->send(json_encode($response));
                }
                $gameroom->players->next();
            }

            
        } else if ($data->method == 'roll') {

            $response = array(
                'action' => 'roll',
                'user' => $player->name,
                'colorset' => $player->colorset,
                'texture' => $player->texture,
                'material' => $player->material,
                'notation' => $data->notation,
                'vectors' => $data->vectors,
                'time' => $data->time
            );

            if ($gameroom) $gameroom->sendAll(json_encode($response));

        } else if ($data->method == 'colorset') {

            $player->colorset = $data->colorset;

            $response = array(
                'action' => $data->method,
                'user' => $player->name,
                'colorset' => $data->colorset,
            );

            //if ($gameroom) $player->connection->send(json_encode($response));

        } else if ($data->method == 'texture') {

            $player->texture = $data->texture;

            $response = array(
                'action' => $data->method,
                'user' => $player->name,
                'texture' => $data->texture,
            );

            //if ($gameroom) $player->connection->send(json_encode($response));

        } else if ($data->method == 'material') {

            $player->material = $data->material;

            $response = array(
                'action' => $data->method,
                'user' => $player->name,
                'material' => $data->material,
            );

            //if ($gameroom) $player->connection->send(json_encode($response));

        } else if ($data->method == 'roomlist') {

            $Rooms = array();

            $this->games->rewind();
            while($this->games->valid()) {
                $room = $this->games->current();

                $roomdata = array(
                    'id' => $room->id,
                    'name' => $room->name,
                    'owner' => $room->owner->connection->resourceId.': '.$room->owner->name,
                    'password' => ($room->password == '') ? 'no' : 'yes',
                    'settings' => $room->settings,
                    'players' => $room->playerlist(),
                    'created' => $room->created,
                    'created_verbose' => $this->time_elapsed(time() - $room->created),
                    'lastactivity' => $room->lastactivity,
                    'lastactivity_verbose' => $this->time_elapsed(time() - $room->lastactivity)
                );

                array_push($Rooms, $roomdata);

                $this->games->next();
            }

            $player->connection->send(json_encode(array('action' => 'roomlist', 'list' => $Rooms)));

        } else {
            $response['method'] = $data->method;
        }
    }

    public function onClose(ConnectionInterface $conn) {

        $player = $this->getPlayer($conn);
        $gameroom = $this->getPlayerRoom($conn);

        if($gameroom) {

            $gameroom->removePlayer($player);

            if ($gameroom->players->count() > 0) {
                
                $roomdata = $gameroom->getRoomData();
                $roomdata['action'] = 'userlist';
                $roomdata['act'] = 'del';
                $roomdata['user'] = $player->name;

                if($gameroom) $gameroom->sendAll(json_encode($roomdata));

            } else {
                $this->games->detach($gameroom);
            }
        }

        $this->clients->detach($conn);
        $this->log("{$conn->resourceId}: Disconnect");
        $this->log('Total Clients: '.$this->clients->count());
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->clients->detach($conn);
        $this->log("{$conn->resourceId}: Error -> {$e}");
        $this->log('Total Clients: '.$this->clients->count());
    }

    public function time_elapsed($secs){
        $bit = array(
            ' year'        => $secs / 31556926 % 12,
            ' week'        => $secs / 604800 % 52,
            ' day'        => $secs / 86400 % 7,
            ' hour'        => $secs / 3600 % 24,
            ' minute'    => $secs / 60 % 60,
            ' second'    => $secs % 60
            );
           
        foreach($bit as $k => $v){
            if($v > 1)$ret[] = $v . $k . 's';
            if($v == 1)$ret[] = $v . $k;
            }
        array_splice($ret, count($ret)-1, 0, 'and');
        $ret[] = 'ago.';
       
        return join(' ', $ret);
    }

    public function now() {
        return date('M j g:i a');
    }

    public function log($message) {
        echo $this->now(). ': '.$message."\n";
    }
}