<?php
// Función que mostrará la página de facturas
function factuspress_invoices_page() {
    // 1) Capturamos variables de $_GET
    $current_page = isset($_GET['factuspress_page']) ? absint($_GET['factuspress_page']) : 1;

    // Filtros
    $identification  = isset($_GET['identification']) ? sanitize_text_field($_GET['identification']) : '';
    $names           = isset($_GET['names']) ? sanitize_text_field($_GET['names']) : '';
    $number          = isset($_GET['number']) ? sanitize_text_field($_GET['number']) : '';
    $prefix          = isset($_GET['prefix']) ? sanitize_text_field($_GET['prefix']) : '';
    $reference_code  = isset($_GET['reference_code']) ? sanitize_text_field($_GET['reference_code']) : '';
    $status          = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

    // 2) Construimos el array con los argumentos que espera factuspress_get_invoices()
    $args = array(
        'page'             => $current_page,
        'identification'   => $identification,
        'names'            => $names,
        'number'           => $number,
        'prefix'           => $prefix,
        'reference_code'   => $reference_code,
        'status'           => $status,
    );

    // 3) Obtenemos las facturas (asegúrate de tener definida la función factuspress_get_invoices())
    $data = factuspress_get_invoices( $args );

    // 4) Verificamos si hay data
    if ( ! $data ) {
        echo '<div class="error"><p><strong>No se encontraron facturas o hubo un error en la solicitud.</strong></p></div>';
        return;
    }

    $invoices   = isset($data['data']['data'])       ? $data['data']['data']       : array();
    $pagination = isset($data['data']['pagination']) ? $data['data']['pagination'] : array();
    ?>
    <h2>Buscar Facturas</h2>
    <form method="get" action="" class="factuspress-search-form">
        <input type="hidden" name="page" value="factuspress_invoices" />
        <label>Identificación:
            <input type="text" name="identification" value="<?php echo esc_attr($identification); ?>">
        </label>
        <label>Nombre:
            <input type="text" name="names" value="<?php echo esc_attr($names); ?>">
        </label>
        <label>Número Factura:
            <input type="text" name="number" value="<?php echo esc_attr($number); ?>">
        </label>
        <label>Prefijo:
            <input type="text" name="prefix" value="<?php echo esc_attr($prefix); ?>">
        </label>
        <label>Código de referencia:
            <input type="text" name="reference_code" value="<?php echo esc_attr($reference_code); ?>">
        </label>
        <label>Estado:
            <select name="status">
                <option value="">Todos</option>
                <option value="1" <?php selected($status, '1'); ?>>Validada</option>
                <option value="0" <?php selected($status, '0'); ?>>Pendiente por validar</option>
            </select>
        </label>
        <input type="submit" class="button button-primary" value="Filtrar">
    </form>
    <?php
    // 5) Mostrar la tabla si hay facturas
    if ( ! empty($invoices) ) {
        echo '<table class="factuspress-awesome-table">';
        echo '<thead><tr>
                <th>ID</th>
                <th>Factura N°</th>
                <th>Referencia</th>
                <th>Estado</th>
                <th>Fecha</th>
                <th>Acciones</th>
            </tr></thead><tbody>';

        foreach ( $invoices as $invoice ) {
            $id             = isset( $invoice['id'] )             ? esc_html($invoice['id']) : 'N/A';
            $invoice_number = isset( $invoice['number'] )          ? esc_html($invoice['number']) : 'N/A';
            $raw_status     = isset( $invoice['status'] )         ? esc_html($invoice['status']) : '';
            $reference_code = isset( $invoice['reference_code'] ) ? esc_html($invoice['reference_code']) : '';
            $date           = isset( $invoice['created_at'] )     ? esc_html($invoice['created_at']) : '';

            // Convertir 1/0 a texto
            if ( $raw_status === '1' ) {
                $status_text = 'Validada';
            } elseif ( $raw_status === '0' ) {
                $status_text = 'Pendiente por validar';
            } else {
                $status_text = $raw_status;
            }

            // Enlaces de acciones:
            // PDF
            $download_pdf_link = add_query_arg(
                array(
                    'action'         => 'factuspress_download_pdf',
                    'invoice_number' => $invoice_number,
                ),
                admin_url('admin-post.php')
            );
            // XML
            $download_xml_link = add_query_arg(
                array(
                    'action'         => 'factuspress_download_xml',
                    'invoice_number' => $invoice_number,
                ),
                admin_url('admin-post.php')
            );
            // Eliminar (botón con ícono de bote de basura)
            $delete_link = add_query_arg(
                array(
                    'action'         => 'factuspress_delete_invoice',
                    'reference_code' => $reference_code,
                ),
                admin_url('admin-post.php')
            );

            echo '<tr>';
            echo '<td>' . $id . '</td>';
            echo '<td>' . $invoice_number . '</td>';
            echo '<td>' . $reference_code . '</td>';
            echo '<td>' . $status_text . '</td>';
            echo '<td>' . $date . '</td>';
            echo '<td>';
                // Ícono PDF
                echo '<a href="' . esc_url($download_pdf_link) . '" target="_blank" title="Ver/Descargar PDF">';
                echo '<span class="dashicons dashicons-visibility"></span>';
                echo '</a>';
                echo ' &nbsp; ';
                // Ícono XML
                echo '<a href="' . esc_url($download_xml_link) . '" target="_blank" title="Descargar XML">';
                echo '<span class="dashicons dashicons-media-code"></span>';
                echo '</a>';
                echo ' &nbsp; ';
                // Ícono Eliminar (bote de basura) con confirmación
                echo '<a href="' . esc_url($delete_link) . '" title="Eliminar factura" onclick="return confirm(\'¿Estás seguro de eliminar esta factura?\');">';
                echo '<span class="dashicons dashicons-trash"></span>';
                echo '</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<div class="error"><p><strong>No se encontraron facturas para este filtro.</strong></p></div>';
    }

    // 6) Paginación
    if ( ! empty($pagination) ) {
        $page_now    = isset($pagination['current_page']) ? absint($pagination['current_page']) : 1;
        $total_pages = isset($pagination['last_page'])    ? absint($pagination['last_page'])    : 1;

        $base_url = remove_query_arg('factuspress_page');
        $common_args = array(
            'page'             => 'factuspress_invoices',
            'identification'   => $identification,
            'names'            => $names,
            'number'           => $number,
            'prefix'           => $prefix,
            'reference_code'   => $reference_code,
            'status'           => $status,
        );

        echo '<div class="factuspress-pagination">';
        if ( $page_now > 1 ) {
            $prev_args = array_merge( $common_args, array(
                'factuspress_page' => $page_now - 1
            ));
            $prev_link = add_query_arg( $prev_args, $base_url );
            echo '<a class="button" href="' . esc_url($prev_link) . '">&laquo; Anterior</a>';
        }
        if ( $page_now < $total_pages ) {
            $next_args = array_merge( $common_args, array(
                'factuspress_page' => $page_now + 1
            ));
            $next_link = add_query_arg( $next_args, $base_url );
            echo ' <a class="button" href="' . esc_url($next_link) . '">Siguiente &raquo;</a>';
        }
        echo ' <p style="display:inline-block; margin-left:10px;">';
        echo 'Página ' . $page_now . ' de ' . $total_pages;
        echo '</p></div>';
    }
}

function factuspress_admin_notice() {
    if ( isset($_GET['factuspress_msg']) && !empty($_GET['factuspress_msg']) ) {
        $notice_type = isset($_GET['factuspress_notice']) ? sanitize_text_field($_GET['factuspress_notice']) : 'success';
        // Selecciona la clase: success (verde) o error (rojo)
        $class = ($notice_type === 'error') ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
        echo '<div class="' . $class . '">';
        echo '<p>' . esc_html( urldecode( $_GET['factuspress_msg'] ) ) . '</p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'factuspress_admin_notice');