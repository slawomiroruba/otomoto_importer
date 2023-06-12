<?php

class MetaBoxTools
{

    /**
     * Aktualizuje dane w custom table dla określonego wpisu i grupy na podstawie podanych pól.
     *
     * @param int $post_id ID wpisu, dla którego mają być zaktualizowane dane w custom table.
     * @param string $group_id ID grupy, która zawiera pola do aktualizacji w custom table.
     * @param array $fields Tablica asocjacyjna z nazwami pól jako klucze i ich wartościami jako wartości.
     * @param string $table_name Nazwa custom table, która ma zostać zaktualizowana.
     * @return bool True, jeśli dane zostały zaktualizowane; w przeciwnym razie false.
     */
    public static function update_custom_table_fields($post_id, $table_name, $fields)
    {
        global $wpdb;

        foreach ($fields as $field_key => $field_value) {
            $meta_key = $field_key;
            $data[$meta_key] = is_array($field_value) ? MetaBoxTools::serialize_array($field_value) : $field_value;
        }
        // throw new Exception(print_r($data, true));
        // Sprawdzenie, czy tabela istnieje w bazie danych
        $table_exists = self::is_table_exists($table_name);

        // Jeśli tabela nie istnieje, rzucamy wyjątek
        if (!$table_exists) {
            // Create table 
            $sql = "CREATE TABLE {$table_name} (
                id int(11) NOT NULL AUTO_INCREMENT,
                post_id int(11) NOT NULL,
                PRIMARY KEY  (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $wpdb->query($sql);    
        }

        // Pobranie listy kolumn z custom table
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}", 0);

        // Przefiltrowanie danych do aktualizacji, aby uwzględnić tylko istniejące kolumny z custom table
        // $data_filtered = array_intersect_key($data, array_flip($columns));
        // throw new Exception(print_r($columns, true));
        // Aktualizacja rekordu w custom table
        $update_data = array();
        foreach ($data as $key => $value) {
            // Sprawdzenie, czy kolumna istnieje w tabeli
            if (in_array($key, $columns)) {
                $update_data[$key] = $value;
            } else {
                // Tworzenie brakującej kolumny
                // throw new Exception(print_r("ALTER TABLE {$table_name} ADD `{$key}` VARCHAR(255)", true));
                $wpdb->query("ALTER TABLE {$table_name} ADD `{$key}` VARCHAR(255)");
                $update_data[$key] = $value;
            }
        }
        if (!empty($update_data)) {
            // Sprawdzenie, czy istnieje rekord o określonym ID
            $existing_record = $wpdb->get_var("SELECT ID FROM {$table_name} WHERE ID = {$post_id}");

            if ($existing_record) {
                // Aktualizacja rekordu w custom table
                return $wpdb->update($table_name, $update_data, array('ID' => $post_id));
            } else {
                // Wstawienie nowego rekordu do custom table
                $update_data['ID'] = $post_id;
                return $wpdb->insert($table_name, $update_data);
            }
        } else {
            return false;
        }
    }

    /**
     * Sprawdza, czy tabela istnieje w bazie danych.
     *
     * @param string $table_name Nazwa tabeli.
     *
     * @return bool True, jeśli tabela istnieje w bazie danych; w przeciwnym razie false.
     */
    public static function is_table_exists($table_name)
    {
        global $wpdb;
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        return $result !== null;
    }

    /**
     * Serializuje tablicę do postaci stringa.
     *
     * @param array $array Tablica do zserializowania.
     * @return string Zserializowana tablica.
     */
    public static function serialize_array($array)
    {
        // Tworzenie tablicy asocjacyjnej z wartościami tablicy
        $data = array();
        foreach ($array as $index => $value) {
            $data[$index] = $value;
        }

        // Serializacja tablicy do postaci stringa
        return serialize($data);
    }

    /**
     * Deserializuje tablicę z postaci stringa.
     *
     * @param string $string Zserializowana tablica.
     * @return array Deserializowana tablica.
     */
    public static function unserialize_array($string)
    {
        // Deserializacja tablicy z postaci stringa
        $data = unserialize($string);
        
        // Tworzenie tablicy z wartościami tablicy asocjacyjnej
        $array = array();
        foreach ($data as $index => $value) {
            $array[$index] = $value;
        }
        
        return $array;
    }
}
