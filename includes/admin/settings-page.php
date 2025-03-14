<?php
// Mostrar la página de configuración
function factuspress_settings_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'factuspress_config';
    $config     = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );

    if ( $config ) {
        $client_id     = $config->client_id;
        $client_secret = $config->client_secret;
        $username      = $config->username;
        $password      = $config->password;
        $env_mode      = ( isset($config->env_mode) ) ? $config->env_mode : 'sandbox'; // Valor por defecto
    } else {
        $client_id     = '';
        $client_secret = '';
        $username      = '';
        $password      = '';
        $env_mode      = 'sandbox';
    }

    ?>
    <div class="wrap factuspress-settings-page">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <form method="post">
            <table class="form-table" class="factuspress-settings-form">
                <tr valign="top">
                    <th scope="row"><label for="factuspress_env_mode">Entorno</label></th>
                    <td>
                        <select id="factuspress_env_mode" name="factuspress_env_mode">
                            <option value="sandbox" <?php selected($env_mode, 'sandbox'); ?>>Sandbox</option>
                            <option value="production" <?php selected($env_mode, 'production'); ?>>Producción</option>
                        </select>
                        <p class="description">Selecciona el entorno para conectarte con Factus.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="factuspress_client_id"><?php _e( 'Client ID', 'factupress' ); ?></label></th>
                    <td><input type="text" id="factuspress_client_id" name="factuspress_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="factuspress_client_secret"><?php _e( 'Client Secret', 'factupress' ); ?></label></th>
                    <td><input type="text" id="factuspress_client_secret" name="factuspress_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="factuspress_username"><?php _e( 'Username', 'factupress' ); ?></label></th>
                    <td><input type="text" id="factuspress_username" name="factuspress_username" value="<?php echo esc_attr( $username ); ?>" class="regular-text" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="factuspress_password"><?php _e( 'Password', 'factupress' ); ?></label></th>
                    <td><input type="password" id="factuspress_password" name="factuspress_password" value="<?php echo esc_attr( $password ); ?>" class="regular-text" required /></td>
                </tr>
            </table>
            <?php submit_button( 'Guardar cambios' ); ?>
        </form>
    </div>
    <?php

    if ( isset( $_POST['submit'] ) ) {
        factuspress_save_config();
    }
}

// Guardar la configuración en la base de datos personalizada
function factuspress_save_config() {
    global $wpdb;

    // Obtener las credenciales del formulario
    $env_mode      = sanitize_text_field( $_POST['factuspress_env_mode'] );
    $client_id     = sanitize_text_field( $_POST['factuspress_client_id'] );
    $client_secret = sanitize_text_field( $_POST['factuspress_client_secret'] );
    $username      = sanitize_text_field( $_POST['factuspress_username'] );
    $password      = sanitize_text_field( $_POST['factuspress_password'] );

    // Definir la URL según 'env_mode'
    // Podrías usar un if:
    if ( $env_mode === 'production' ) {
        $url = 'https://api.factus.com.co/oauth/token';
    } else {
        $url = 'https://api-sandbox.factus.com.co/oauth/token';
    }

    // Preparar los datos para la solicitud
    $body = array(
        'grant_type'    => 'password',
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'username'      => $username,
        'password'      => $password,
    );

    // Realizar la solicitud
    $response = wp_remote_post( $url, array(
        'method'  => 'POST',
        'body'    => $body,
        'headers' => array( 'Accept' => 'application/json' ),
    ));

    if ( is_wp_error( $response ) ) {
        echo '<div class="error"><p><strong>Error de conexión:</strong> No se pudo establecer conexión con la API de Factus. Verifique sus credenciales.</p></div>';
        return;
    }

    // Procesar la respuesta
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $data['access_token'] ) ) {
        // Toma los valores devueltos
        $access_token  = sanitize_text_field( $data['access_token'] );
        $refresh_token = sanitize_text_field( $data['refresh_token'] );
        $token_type    = sanitize_text_field( $data['token_type'] );
        $expires_in    = (int) $data['expires_in'];
        $token_expiry  = date('Y-m-d H:i:s', time() + $expires_in);

        $table_name = $wpdb->prefix . 'factuspress_config';
        $existing_config = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = 1" );

        $data_to_save = array(
            'env_mode'      => $env_mode, // <--- Guardamos el entorno
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'username'      => $username,
            'password'      => $password,
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'token_type'    => $token_type,
            'expires_in'    => $expires_in,
            'token_expiry'  => $token_expiry,
        );
        $format_array = array( '%s','%s','%s','%s','%s','%s','%s','%s','%d','%s' );

        if ( $existing_config ) {
            $wpdb->update(
                $table_name,
                $data_to_save,
                array( 'id' => 1 ),
                $format_array,
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $table_name,
                $data_to_save,
                $format_array
            );
        }

        echo '<div class="updated"><p><strong>Los cambios se han guardado y el token ha sido actualizado correctamente.</strong></p></div>';

    } else {
        echo '<div class="error"><p><strong>Error de autenticación:</strong> Las credenciales no son válidas o hubo un problema con la API.</p></div>';
    }
}
