<?php

/**
 * Plugin Name: Product Sync
 * Description: Simple product management UI for the integration project.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function integration_product_sync_table_name()
{
    global $wpdb;
    return $wpdb->prefix . 'integration_products';
}

function integration_product_sync_create_or_update_table()
{
    global $wpdb;

    $table_name = integration_product_sync_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_central_id VARCHAR(36) NULL,
        name VARCHAR(255) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantity INT NOT NULL DEFAULT 0,
        description TEXT NULL,
        sync_status VARCHAR(50) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY product_central_id (product_central_id)
    ) {$charset_collate};";

    dbDelta($sql);
}

function integration_product_sync_activate()
{
    integration_product_sync_create_or_update_table();

    integration_product_sync_ensure_schema();
    integration_product_sync_backfill_product_central_ids();
}
register_activation_hook(__FILE__, 'integration_product_sync_activate');

function integration_product_sync_ensure_schema()
{
    global $wpdb;

    $table_name = integration_product_sync_table_name();
    $table_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
    );

    if ($table_exists !== $table_name) {
        integration_product_sync_create_or_update_table();
        return;
    }

    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}", 0);
    if (!in_array('product_central_id', $columns, true)) {
        $wpdb->query(
            "ALTER TABLE {$table_name} ADD COLUMN product_central_id VARCHAR(36) NULL AFTER id"
        );
    }

    if (in_array('central_id', $columns, true)) {
        // Migrate legacy IDs once, then remove old column.
        $wpdb->query(
            "UPDATE {$table_name} SET product_central_id = central_id "
            . "WHERE (product_central_id IS NULL OR product_central_id = '') "
            . "AND central_id IS NOT NULL AND central_id <> ''"
        );

        $legacy_index = $wpdb->get_var(
            "SHOW INDEX FROM {$table_name} WHERE Key_name = 'central_id'"
        );
        if ($legacy_index) {
            $wpdb->query("ALTER TABLE {$table_name} DROP INDEX central_id");
        }

        $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN central_id");
    }

    $central_index = $wpdb->get_var(
        "SHOW INDEX FROM {$table_name} WHERE Key_name = 'product_central_id'"
    );
    if (!$central_index) {
        $wpdb->query(
            "ALTER TABLE {$table_name} ADD UNIQUE KEY product_central_id (product_central_id)"
        );
    }
}

function integration_product_sync_generate_product_central_id()
{
    if (function_exists('wp_generate_uuid4')) {
        return wp_generate_uuid4();
    }

    return uniqid('central-', true);
}

function integration_product_sync_backfill_product_central_ids()
{
    global $wpdb;

    $table_name = integration_product_sync_table_name();
    $products_without_product_central_id = $wpdb->get_results(
        "SELECT id FROM {$table_name} WHERE product_central_id IS NULL OR product_central_id = ''"
    );

    if (empty($products_without_product_central_id)) {
        return;
    }

    foreach ($products_without_product_central_id as $product) {
        $product_central_id = integration_product_sync_generate_product_central_id();

        $wpdb->update(
            $table_name,
            array('product_central_id' => $product_central_id),
            array('id' => (int) $product->id),
            array('%s'),
            array('%d')
        );
    }
}

function integration_product_sync_send_event_to_wp_sender($action, $product_id, $product_data = array())
{
    $endpoint = 'http://wp_sender:8000/product-event';

    $payload = array(
        'action' => $action,
        'id' => (int) $product_id,
        'product_central_id' => isset($product_data['product_central_id'])
            ? sanitize_text_field((string) $product_data['product_central_id'])
            : '',
        'name' => isset($product_data['name']) ? sanitize_text_field((string) $product_data['name']) : '',
        'price' => isset($product_data['price']) ? (float) $product_data['price'] : 0,
        'quantity' => isset($product_data['quantity']) ? (int) $product_data['quantity'] : 0,
        'description' => isset($product_data['description']) ? sanitize_text_field((string) $product_data['description']) : '',
    );

    // Flow: WordPress hook -> wp_sender -> XML -> RabbitMQ topic exchange -> queue for Odoo receiver.
    $response = wp_remote_post(
        $endpoint,
        array(
            'timeout' => 5,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
        )
    );

    if (is_wp_error($response)) {
        return array(
            'ok' => false,
            'error' => $response->get_error_message(),
        );
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return array(
            'ok' => false,
            'error' => 'wp_sender HTTP ' . $status,
        );
    }

    return array('ok' => true, 'error' => '');
}

function integration_product_sync_on_created($product_id, $product_data)
{
    // Legacy hook kept for compatibility. Sync is executed directly in action handler.
}
add_action('integration_product_created', 'integration_product_sync_on_created', 10, 2);

function integration_product_sync_on_updated($product_id, $product_data)
{
    // Legacy hook kept for compatibility. Sync is executed directly in action handler.
}
add_action('integration_product_updated', 'integration_product_sync_on_updated', 10, 2);

function integration_product_sync_on_deleted($product_id, $product_data)
{
    // Legacy hook kept for compatibility. Sync is executed directly in action handler.
}
add_action('integration_product_deleted', 'integration_product_sync_on_deleted', 10, 2);

function integration_product_sync_admin_menu()
{
    add_menu_page(
        'Product Sync',
        'Product Sync',
        'manage_options',
        'integration-product-sync',
        'integration_product_sync_render_page',
        'dashicons-products',
        26
    );
}
add_action('admin_menu', 'integration_product_sync_admin_menu');

function integration_product_sync_redirect_with_message($message)
{
    $url = add_query_arg(
        array(
            'page' => 'integration-product-sync',
            'message' => rawurlencode($message),
        ),
        admin_url('admin.php')
    );

    wp_redirect($url);
    exit;
}

function integration_product_sync_handle_actions()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html('You are not allowed to access this page.'));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['integration_action'])) {
        return;
    }

    check_admin_referer('integration_product_sync_action', 'integration_nonce');

    global $wpdb;
    $table_name = integration_product_sync_table_name();
    integration_product_sync_ensure_schema();

    $action = sanitize_text_field(wp_unslash($_POST['integration_action']));

    if ($action === 'create') {
        $product_central_id = integration_product_sync_generate_product_central_id();
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $price = (float) sanitize_text_field(wp_unslash($_POST['price'] ?? '0'));
        $quantity = (int) sanitize_text_field(wp_unslash($_POST['quantity'] ?? '0'));
        $description = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
        $sync_status = sanitize_text_field(wp_unslash($_POST['sync_status'] ?? 'pending'));

        $wpdb->insert(
            $table_name,
            array(
                'product_central_id' => $product_central_id,
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'description' => $description,
                'sync_status' => $sync_status,
            ),
            array('%s', '%s', '%f', '%d', '%s', '%s')
        );

        if ($wpdb->last_error !== '') {
            integration_product_sync_redirect_with_message('Create failed.');
        }

        $product_id = (int) $wpdb->insert_id;
        if ($product_id <= 0) {
            integration_product_sync_redirect_with_message('Create failed.');
        }

        $event_payload = array(
            'id' => $product_id,
            'product_central_id' => $product_central_id,
            'name' => $name,
            'price' => $price,
            'quantity' => $quantity,
            'description' => $description,
            'sync_status' => $sync_status,
        );

        $sync_result = integration_product_sync_send_event_to_wp_sender(
            'created',
            $product_id,
            $event_payload
        );

        if (!$sync_result['ok']) {
            $wpdb->update(
                $table_name,
                array('sync_status' => 'sync_failed'),
                array('id' => $product_id),
                array('%s'),
                array('%d')
            );

            integration_product_sync_redirect_with_message(
                'Product created locally, but sync failed: ' . $sync_result['error']
            );
        }

        // Keep hooks for compatibility.
        do_action('integration_product_created', $product_id, $event_payload);

        $wpdb->update(
            $table_name,
            array('sync_status' => 'synced'),
            array('id' => $product_id),
            array('%s'),
            array('%d')
        );

        integration_product_sync_redirect_with_message('Product created.');
    }

    if ($action === 'update') {
        $id = (int) sanitize_text_field(wp_unslash($_POST['id'] ?? '0'));
        if ($id <= 0) {
            integration_product_sync_redirect_with_message('Update failed. Invalid product ID.');
        }

        $existing_product = $wpdb->get_row(
            $wpdb->prepare("SELECT id, product_central_id FROM {$table_name} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$existing_product) {
            integration_product_sync_redirect_with_message('Update failed. Product not found.');
        }

        $product_central_id = isset($existing_product['product_central_id'])
            ? (string) $existing_product['product_central_id']
            : '';
        if ($product_central_id === '') {
            $product_central_id = integration_product_sync_generate_product_central_id();
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $price = (float) sanitize_text_field(wp_unslash($_POST['price'] ?? '0'));
        $quantity = (int) sanitize_text_field(wp_unslash($_POST['quantity'] ?? '0'));
        $description = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
        $sync_status = sanitize_text_field(wp_unslash($_POST['sync_status'] ?? 'pending'));

        $result = $wpdb->update(
            $table_name,
            array(
                'product_central_id' => $product_central_id,
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'description' => $description,
                'sync_status' => $sync_status,
            ),
            array('id' => $id),
            array('%s', '%s', '%f', '%d', '%s', '%s'),
            array('%d')
        );

        if ($result === false || $wpdb->last_error !== '') {
            integration_product_sync_redirect_with_message('Update failed.');
        }

        $event_payload = array(
            'id' => $id,
            'product_central_id' => $product_central_id,
            'name' => $name,
            'price' => $price,
            'quantity' => $quantity,
            'description' => $description,
            'sync_status' => $sync_status,
        );

        $sync_result = integration_product_sync_send_event_to_wp_sender(
            'updated',
            $id,
            $event_payload
        );

        if (!$sync_result['ok']) {
            $wpdb->update(
                $table_name,
                array('sync_status' => 'sync_failed'),
                array('id' => $id),
                array('%s'),
                array('%d')
            );

            integration_product_sync_redirect_with_message(
                'Product updated locally, but sync failed: ' . $sync_result['error']
            );
        }

        // Keep hooks for compatibility.
        do_action('integration_product_updated', $id, $event_payload);

        $wpdb->update(
            $table_name,
            array('sync_status' => 'synced'),
            array('id' => $id),
            array('%s'),
            array('%d')
        );

        integration_product_sync_redirect_with_message('Product updated.');
    }

    if ($action === 'delete') {
        $id = (int) sanitize_text_field(wp_unslash($_POST['id'] ?? '0'));
        if ($id <= 0) {
            integration_product_sync_redirect_with_message('Delete failed. Invalid product ID.');
        }

        $existing_product = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id),
            ARRAY_A
        );

        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));
        if ($result === false || $wpdb->last_error !== '') {
            integration_product_sync_redirect_with_message('Delete failed.');
        }

        $event_payload = array(
            'id' => $id,
            'product_central_id' => isset($existing_product['product_central_id'])
                ? $existing_product['product_central_id']
                : '',
            'name' => isset($existing_product['name']) ? $existing_product['name'] : '',
            'price' => isset($existing_product['price']) ? $existing_product['price'] : 0,
            'quantity' => isset($existing_product['quantity']) ? $existing_product['quantity'] : 0,
            'description' => isset($existing_product['description']) ? $existing_product['description'] : '',
            'sync_status' => isset($existing_product['sync_status']) ? $existing_product['sync_status'] : 'pending',
        );

        $sync_result = integration_product_sync_send_event_to_wp_sender(
            'deleted',
            $id,
            $event_payload
        );

        if (!$sync_result['ok']) {
            integration_product_sync_redirect_with_message(
                'Product deleted locally, but sync failed: ' . $sync_result['error']
            );
        }

        // Keep hooks for compatibility.
        do_action('integration_product_deleted', $id, $event_payload);

        integration_product_sync_redirect_with_message('Product deleted.');
    }
}

function integration_product_sync_render_page()
{
    integration_product_sync_handle_actions();
    integration_product_sync_ensure_schema();

    if (!current_user_can('manage_options')) {
        wp_die(esc_html('You are not allowed to access this page.'));
    }

    global $wpdb;
    $table_name = integration_product_sync_table_name();

    $edit_id = 0;
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
        $edit_id = (int) sanitize_text_field(wp_unslash($_GET['id']));
    }

    $editing_product = null;
    if ($edit_id > 0) {
        $editing_product = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $edit_id));
    }

    $products = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id DESC");

    $message = '';
    if (isset($_GET['message'])) {
        $message = sanitize_text_field(wp_unslash($_GET['message']));
    }
?>
    <div class="wrap">
        <h1><?php echo esc_html('Product Sync'); ?></h1>

        <?php if ($message !== '') : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <h2><?php echo esc_html($editing_product ? 'Edit Product' : 'Create Product'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('integration_product_sync_action', 'integration_nonce'); ?>
            <input type="hidden" name="integration_action" value="<?php echo esc_attr($editing_product ? 'update' : 'create'); ?>" />
            <?php if ($editing_product) : ?>
                <input type="hidden" name="id" value="<?php echo esc_attr((string) $editing_product->id); ?>" />
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="name"><?php echo esc_html('Name'); ?></label></th>
                    <td><input name="name" id="name" type="text" class="regular-text" required value="<?php echo esc_attr($editing_product ? $editing_product->name : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="price"><?php echo esc_html('Price'); ?></label></th>
                    <td><input name="price" id="price" type="number" step="0.01" min="0" required value="<?php echo esc_attr($editing_product ? (string) $editing_product->price : '0.00'); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="quantity"><?php echo esc_html('Quantity'); ?></label></th>
                    <td><input name="quantity" id="quantity" type="number" min="0" required value="<?php echo esc_attr($editing_product ? (string) $editing_product->quantity : '0'); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="description"><?php echo esc_html('Description'); ?></label></th>
                    <td><input name="description" id="description" type="text" class="regular-text" value="<?php echo esc_attr($editing_product ? $editing_product->description : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="sync_status"><?php echo esc_html('Sync Status'); ?></label></th>
                    <td><input name="sync_status" id="sync_status" type="text" class="regular-text" value="<?php echo esc_attr($editing_product ? $editing_product->sync_status : 'pending'); ?>" /></td>
                </tr>
            </table>

            <?php submit_button($editing_product ? 'Update Product' : 'Create Product'); ?>
        </form>

        <hr />

        <h2><?php echo esc_html('Product Overview'); ?></h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html('ID'); ?></th>
                    <th><?php echo esc_html('Name'); ?></th>
                    <th><?php echo esc_html('Price'); ?></th>
                    <th><?php echo esc_html('Quantity'); ?></th>
                    <th><?php echo esc_html('Description'); ?></th>
                    <th><?php echo esc_html('Sync Status'); ?></th>
                    <th><?php echo esc_html('Created At'); ?></th>
                    <th><?php echo esc_html('Updated At'); ?></th>
                    <th><?php echo esc_html('Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)) : ?>
                    <tr>
                        <td colspan="9"><?php echo esc_html('No products found.'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($products as $product) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $product->id); ?></td>
                            <td><?php echo esc_html($product->name); ?></td>
                            <td><?php echo esc_html((string) $product->price); ?></td>
                            <td><?php echo esc_html((string) $product->quantity); ?></td>
                            <td><?php echo esc_html($product->description); ?></td>
                            <td><?php echo esc_html($product->sync_status); ?></td>
                            <td><?php echo esc_html($product->created_at); ?></td>
                            <td><?php echo esc_html($product->updated_at); ?></td>
                            <td>
                                <a href="<?php echo esc_attr(add_query_arg(array('page' => 'integration-product-sync', 'action' => 'edit', 'id' => (int) $product->id), admin_url('admin.php'))); ?>">
                                    <?php echo esc_html('Edit'); ?>
                                </a>
                                |
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('integration_product_sync_action', 'integration_nonce'); ?>
                                    <input type="hidden" name="integration_action" value="delete" />
                                    <input type="hidden" name="id" value="<?php echo esc_attr((string) $product->id); ?>" />
                                    <button type="submit" class="button-link-delete"><?php echo esc_html('Delete'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php
}
