<?php
/**
 * Extrae los departamentos únicos de la lista de municipios.
 *
 * @return array Opciones de departamentos para el select.
 */
function factuspress_get_departments_options() {
	$data = factuspress_get_municipios_data();
	$departments = array();
	if ( ! empty( $data ) ) {
		foreach ( $data as $municipio ) {
			if ( isset( $municipio['department'] ) ) {
				$departments[] = $municipio['department'];
			}
		}
	}
	// Elimina duplicados y ordena
	$departments = array_unique( $departments );
	sort( $departments );
	$options = array( '' => __( 'Seleccione un departamento', 'factuspress' ) );
	foreach ( $departments as $dep ) {
		$options[ $dep ] = $dep;
	}
	return $options;
}

/**
 * Construye el array de opciones para el select de municipios.
 * Inicialmente se deja vacío ya que se actualizará vía JavaScript.
 *
 * @return array Opciones para el select.
 */
function factuspress_get_municipios_options() {
	return array( '' => __( 'Seleccione un municipio', 'factuspress' ) );
}

/**
 * Hook para modificar los campos de checkout.
 * Se elimina el campo por defecto "billing_state" y se agregan "billing_department" y "billing_municipio".
 */
add_filter( 'woocommerce_checkout_fields', 'factuspress_custom_checkout_fields' );
function factuspress_custom_checkout_fields( $fields ) {
	// Eliminar el campo por defecto de Región/Provincia
	unset( $fields['billing']['billing_state'] );

    // Eliminar también el campo de Ciudad/Población
    unset( $fields['billing']['billing_city'] );

	// Agregar campo de Departamento
	$fields['billing']['billing_department'] = array(
		'type'     => 'select',
		'label'    => __( 'Departamento', 'factuspress' ),
		'required' => true,
		'class'    => array( 'form-row-wide' ),
		'clear'    => true,
		'priority' => 55,
		'options'  => factuspress_get_departments_options(),
	);

	// Agregar campo de Municipio
	$fields['billing']['billing_municipio'] = array(
		'type'     => 'select',
		'label'    => __( 'Municipio', 'factuspress' ),
		'required' => true,
		'class'    => array( 'form-row-wide' ),
		'clear'    => true,
		'priority' => 65,
		'options'  => factuspress_get_municipios_options(),
	);

	return $fields;
}

/**
 * Script para:
 *   - Mostrar u ocultar los campos "Departamento" y "Municipio" según el país (solo si es Colombia).
 *   - Actualizar dinámicamente el select de municipios en función del departamento seleccionado.
 */
add_action( 'wp_footer', 'factuspress_custom_fields_script' );
function factuspress_custom_fields_script() {
	if ( ! is_checkout() ) {
		return;
	}
	// Obtener los municipios completos de la API.
	$municipios_data = factuspress_get_municipios_data();
	?>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		// Variable con la lista completa de municipios.
		var factuspressMunicipios = <?php echo wp_json_encode( $municipios_data ); ?>;

		// Función para mostrar u ocultar los campos personalizados según el país.
		function toggleCustomFields() {
			var country = $('#billing_country').val();
			if ( country === 'CO' ) {
				$('#billing_department_field, #billing_municipio_field').show();
			} else {
				$('#billing_department_field, #billing_municipio_field').hide();
			}
		}

		// Función que actualiza el select de municipios según el departamento seleccionado.
		function updateMunicipioOptions() {
			var selectedDepartment = $('#billing_department').val();
			var municipioSelect = $('#billing_municipio');
			municipioSelect.empty();
			municipioSelect.append($('<option>', { value: '', text: '<?php echo esc_js( __( "Seleccione un municipio", "factuspress" ) ); ?>' }));
			if ( selectedDepartment ) {
				// Comparar sin distinguir mayúsculas/minúsculas.
				selectedDepartment = selectedDepartment.toLowerCase();
				$.each(factuspressMunicipios, function(index, municipio) {
					if ( municipio.department && municipio.department.toLowerCase() === selectedDepartment ) {
						municipioSelect.append($('<option>', { value: municipio.id, text: municipio.name }));
					}
				});
			}
		}

		// Ejecutar funciones al cargar la página.
		toggleCustomFields();
		updateMunicipioOptions();

		// Actualizar cuando se cambia el país.
		$('#billing_country').on('change', function() {
			toggleCustomFields();
		});

		// Actualizar el select de municipios cuando se cambia el departamento.
		$('#billing_department').on('change', function() {
			updateMunicipioOptions();
		});
	});
	</script>
	<?php
}

//Hook para mostrar los datos de departamentos y municipios
add_filter( 'woocommerce_order_formatted_billing_address', 'factuspress_add_department_municipio_to_billing_address', 10, 2 );
function factuspress_add_department_municipio_to_billing_address( $address, $order ) {
    // Obtener meta del pedido
    $department_id_or_name = $order->get_meta('_billing_department');
    $municipio_id_or_name  = $order->get_meta('_billing_municipio');

    /**
     * Si en tus campos guardaste directamente el 'nombre',
     * entonces puedes usarlo tal cual.
     *
     * Si guardaste el 'id', tendrás que hacer una búsqueda del nombre correspondiente.
     * Suponiendo que factuspress_get_municipios_data() retorna la lista
     * y tuviéramos algo como:
     */
    $municipio_name = factuspress_lookup_municipio_name_by_id( $municipio_id_or_name );

    /**
     * Y si department es solo el nombre, úsalo directo. Si también fue ID,
     * puedes hacer algo similar para encontrar el nombre de departamento
     * en tu array.
     */

    $address['state'] = $department_id_or_name; // lo mostramos como state
    $address['city']  = $municipio_name;        // lo mostramos como city

    return $address;
}

function factuspress_lookup_municipio_name_by_id( $municipio_id ) {
    $all_municipios = factuspress_get_municipios_data();
    if ( ! empty( $all_municipios ) ) {
        foreach ( $all_municipios as $mun ) {
            if ( isset($mun['id']) && (string) $mun['id'] === (string) $municipio_id ) {
                return $mun['name'];
            }
        }
    }
    return ''; // Si no se encontró
}


// Hook para guardar los campos personalizados en la meta del pedido.
add_action( 'woocommerce_admin_order_data_after_billing_address', 'factuspress_display_billing_department_municipio_admin', 10, 1 );
function factuspress_display_billing_department_municipio_admin( $order ) {
    // Asegurarnos que $order sea un objeto WC_Order
    if ( is_int( $order ) ) {
        $order = wc_get_order( $order );
    }

    $department = $order->get_meta('_billing_department');
    $municipio  = $order->get_meta('_billing_municipio');

    echo '<p><strong>' . __('Departamento:', 'factuspress') . '</strong> ' . esc_html( $department ) . '</p>';
    echo '<p><strong>' . __('Municipio:', 'factuspress') . '</strong> ' . esc_html( $municipio ) . '</p>';
}
