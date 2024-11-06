<?php

$servername = "xxxxxxxxx";
$username = "xxxxxxxxx";
$password = 'xxxxxxxxxxx';
$dbname = "xxxxxxxxxxx";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}
else {
    echo "Connection Successful!\n";
}

class Upload {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
        if ($this->conn->connect_error) {
            die("Connection Failed: " . $this->conn->connect_error);
        }
    }

    public function loadJson($jsonfile) {
        $carddata = file_get_contents($jsonfile);
        $cards = json_decode($carddata, true);
        if (!$cards) {
            die("ERROR: failed to decode JSON.");
        }
        echo "Loading JSON data...\n";
        $this->displayProgress(count($cards), function($current) {
            echo "\rProcessed: $current cards";
        });
        echo "\nFinished loading JSON data!\n";
        return $cards;
    }

    private function loadExistingCardIds() {
        $existing_ids = [];
        $sql = "SELECT card_char_id FROM magic_criteria";
        $result = $this->conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $existing_ids[$row['card_char_id']] = true;
        }
        $result->free();
        return $existing_ids;
    }

    private function ensureCardIdExists($cardId) {
        $stmt = $this->conn->prepare("INSERT IGNORE INTO magic_criteria (card_id) VALUES (?)");
        $stmt->bind_param("i", $cardId);
        $stmt->execute();
        $stmt->close();
    }

    public function insertData($cards) {
        $counter = 1;
        $existing_ids = $this->loadExistingCardIds();
        $batch = [];
        foreach ($cards as $index => $card) {
            $charcardid = $card["id"] ?? null;
        
            if (!$charcardid) {
                echo "No 'id' key found or empty for this card data.\n";
                continue;
            }
        
            if (strlen($charcardid) > 255) {
                die("ERROR: card_id '$charcardid' exceeds the length limit of 255 characters.");
            }
        
            if (isset($existing_ids[$charcardid])) {
                echo "Skipped: Card ID $charcardid already exists.\n";
                continue;
            }
            
            $batch[] = [
                'card_id' => $counter,
                'name_of_card' => $card['name'] ?? null,
                'card_char_id' => $card['card_char_id'] ?? null,
                'mana_cost' => $this->getManaCost($card['mana_cost'] ?? null),
                'mana_type' => $this->getManaType($card['produced_mana'] ?? []),
                'mana_value' => $card['cmc'] ?? null,
                'power' => isset($card['power']) ? intval($card['power']) : null,
                'toughness' => isset($card['toughness']) ? intval($card['toughness']) : null,
                'expansion' => $card['set_name'] ?? null,
                'rarity' => $card['rarity'] ?? null,
                'card_number' => isset($card['collector_number']) ? intval($card['collector_number']) : null,
                'artist' => $card['artist'] ?? null,
            ];
            $counter++;
        }
    
        if (!empty($batch)) {
            $this->executeBatchInsert($batch);
        }
    }
    
    private function executeBatchInsert($batch) {        
        $stmt_magic = $this->conn->prepare("INSERT INTO magic_criteria (card_id, card_char_id, mana_cost, mana_type, mana_value, power, toughness, expansion, rarity, card_number, artist, name_of_card)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($batch as $card) {  
            $stmt_magic->bind_param(
                "issssiiissis",
                $card['card_id'],
                $card['card_char_id'],
                $card['mana_cost'],
                $card['mana_type'],
                $card['mana_value'],
                $card['power'],
                $card['toughness'],
                $card['expansion'],
                $card['rarity'],
                $card['card_number'],
                $card['artist'],
                $card['name_of_card']
            );

            if (!$stmt_magic->execute()) {
                echo "Error executing statement: " . $stmt_magic->error . "\n";
            }
        }
        $stmt_magic->close();
    }
    
    private function getManaCost($mana_cost) {
        return $mana_cost ? strlen(preg_replace('/[^0-9]/', '', $mana_cost)) + substr_count($mana_cost, '{') : null;
    }

    private function getManaType($mana_type) {
        return !empty($mana_type) ? implode(',', $mana_type) : null;
    }

    private function displayProgress($total, $callback, $current = 0) {
        if ($current > 0) {
            $callback($current);
        }
    }
}

$upload = new Upload($conn);
$cards = $upload->loadJson('cleaned_cards.json');
$upload->insertData($cards);

$conn->close();

?>
