<?php
// Crear la tabla personalizada en la base de datos
function factuspress_create_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'factuspress_config';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        client_id varchar(255) NOT NULL,
        client_secret varchar(255) NOT NULL,
        username varchar(255) NOT NULL,
        password varchar(255) NOT NULL,
        access_token text NOT NULL,
        refresh_token text NOT NULL,
        token_type varchar(50) NOT NULL,
        expires_in int NOT NULL,
        token_expiry datetime NOT NULL,
        env_mode varchar(20) NOT NULL DEFAULT 'sandbox',
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
