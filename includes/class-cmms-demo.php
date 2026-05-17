<?php
/**
 * Demo Account generator. Creates a fully populated sample organization so
 * an administrator can explore CMMS Light without manually building data.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Demo {

    /** Suffix appended to demo emails so they don't collide with existing users. */
    private static $suffix = '';

    /**
     * Create the entire demo dataset. Returns an array with the generated
     * credentials and account info, or WP_Error on failure.
     */
    public static function create() {
        self::$suffix = '-' . substr( md5( (string) microtime( true ) ), 0, 6 );

        // 1) Owner WP user + account + cmms owner row.
        // Pass auto_login=false so the global admin's session is left untouched -
        // otherwise the redirect after demo creation would 403 because the auth
        // cookies would be swapped to the new owner.
        $owner_email = 'owner' . self::$suffix . '@cmms-demo.local';
        $owner_pwd   = 'demo1234';
        $signup = CMMS_Accounts::signup( array(
            'name'         => 'Demo Owner',
            'email'        => $owner_email,
            'password'     => $owner_pwd,
            'organization' => 'Demo Organization' . self::$suffix,
            'phone'        => '050-0000001',
        ), false );
        if ( is_wp_error( $signup ) ) return $signup;

        $owner_wp = get_user_by( 'email', $owner_email );
        $owner    = $owner_wp ? CMMS_Users::get_by_wp_id( $owner_wp->ID ) : null;
        if ( ! $owner ) {
            return new WP_Error( 'demo_failed', 'Could not load demo owner.' );
        }
        $account_id = (int) $owner->account_id;

        // 2) Three more users.
        $users_meta = array(
            array( 'role' => CMMS_Auth::ROLE_MANAGER,    'name' => 'Demo Manager',    'email' => 'manager' . self::$suffix . '@cmms-demo.local',    'phone' => '050-0000002' ),
            array( 'role' => CMMS_Auth::ROLE_TECHNICIAN, 'name' => 'Demo Technician', 'email' => 'technician' . self::$suffix . '@cmms-demo.local', 'phone' => '050-0000003' ),
            array( 'role' => CMMS_Auth::ROLE_REPORTER,   'name' => 'Demo Reporter',   'email' => 'reporter' . self::$suffix . '@cmms-demo.local',   'phone' => '050-0000004' ),
        );
        $user_ids = array( CMMS_Auth::ROLE_OWNER => (int) $owner->id );
        foreach ( $users_meta as $u ) {
            $cmms_id = CMMS_Users::add_user_to_account( $account_id, $u['email'], $u['name'], 'demo1234', $u['role'], $u['phone'] );
            if ( is_wp_error( $cmms_id ) ) continue;
            $user_ids[ $u['role'] ] = (int) $cmms_id;
        }

        // 3) Assets.
        $asset_ids = array();
        $assets = array(
            array( 'name' => 'Production Line A',   'asset_type' => 'machine',   'location' => 'Floor 1, Hall North',   'description' => 'Main production conveyor and packaging unit.' ),
            array( 'name' => 'Forklift #3',         'asset_type' => 'vehicle',   'location' => 'Warehouse',             'description' => 'Toyota 2.5T LPG forklift.' ),
            array( 'name' => 'HVAC - Office Wing',  'asset_type' => 'equipment', 'location' => 'Office building',       'description' => 'Roof-mounted HVAC unit serving offices.' ),
            array( 'name' => 'Cold Storage Room 2', 'asset_type' => 'location',  'location' => 'Floor 1, Hall South',   'description' => 'Walk-in chiller, target -2 to +4 C.' ),
        );
        foreach ( $assets as $a ) {
            $a['created_by'] = $user_ids[ CMMS_Auth::ROLE_OWNER ];
            $aid = CMMS_Assets::create( $account_id, $a );
            if ( $aid && ! is_wp_error( $aid ) ) {
                $asset_ids[] = (int) $aid;
            }
        }

        // 4) Resolve auto-created categories.
        $cats = CMMS_Categories::list_by_account( $account_id, true );
        $cat_by_name = array();
        foreach ( $cats as $c ) {
            $cat_by_name[ strtolower( $c->name ) ] = (int) $c->id;
        }
        $cat = function( $key ) use ( $cat_by_name ) {
            foreach ( $cat_by_name as $name => $id ) {
                if ( strpos( $name, $key ) !== false ) return $id;
            }
            return 0;
        };

        // 5) Tasks across statuses + priorities.
        $today     = current_time( 'Y-m-d' );
        $tomorrow  = date( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) );
        $yesterday = date( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) );
        $next_week = date( 'Y-m-d', strtotime( '+7 days', current_time( 'timestamp' ) ) );

        $tasks = array(
            array(
                'title'           => 'Replace V-belt on Production Line A',
                'description'     => 'Belt is showing visible cracks. Replacement part already on the shelf.',
                'category_id'     => $cat( 'breakdown' ),
                'asset_id'        => $asset_ids[0] ?? 0,
                'priority'        => 'urgent',
                'status'          => 'open',
                'due_date'        => $today,
                'manager_id'      => $user_ids[ CMMS_Auth::ROLE_MANAGER ] ?? 0,
                'assigned_to'     => $user_ids[ CMMS_Auth::ROLE_TECHNICIAN ] ?? 0,
                'recurrence_type' => 'one_time',
            ),
            array(
                'title'           => 'Forklift #3 monthly inspection',
                'description'     => 'Hydraulics, brakes, mast lift, tire wear. Log results in maintenance binder.',
                'category_id'     => $cat( 'preventive' ),
                'asset_id'        => $asset_ids[1] ?? 0,
                'priority'        => 'normal',
                'status'          => 'in_progress',
                'due_date'        => $tomorrow,
                'manager_id'      => $user_ids[ CMMS_Auth::ROLE_MANAGER ] ?? 0,
                'assigned_to'     => $user_ids[ CMMS_Auth::ROLE_TECHNICIAN ] ?? 0,
                'recurrence_type' => 'monthly',
                'recurrence_interval' => 1,
            ),
            array(
                'title'           => 'HVAC filter change',
                'description'     => 'Quarterly filter replacement on office HVAC.',
                'category_id'     => $cat( 'preventive' ),
                'asset_id'        => $asset_ids[2] ?? 0,
                'priority'        => 'low',
                'status'          => 'completed',
                'due_date'        => $yesterday,
                'manager_id'      => $user_ids[ CMMS_Auth::ROLE_MANAGER ] ?? 0,
                'assigned_to'     => $user_ids[ CMMS_Auth::ROLE_TECHNICIAN ] ?? 0,
                'recurrence_type' => 'every_x',
                'recurrence_interval' => 90,
            ),
            array(
                'title'           => 'Cold storage temperature spike',
                'description'     => 'Reporter noted temp at +6 C this morning. Investigate door seal and compressor.',
                'category_id'     => $cat( 'breakdown' ),
                'asset_id'        => $asset_ids[3] ?? 0,
                'priority'        => 'high',
                'status'          => 'waiting',
                'due_date'        => $today,
                'manager_id'      => $user_ids[ CMMS_Auth::ROLE_MANAGER ] ?? 0,
                'assigned_to'     => $user_ids[ CMMS_Auth::ROLE_TECHNICIAN ] ?? 0,
                'recurrence_type' => 'one_time',
            ),
            array(
                'title'           => 'Quarterly safety walk-through',
                'description'     => 'Walk all production zones. Document issues per the safety checklist.',
                'category_id'     => $cat( 'planned' ),
                'asset_id'        => 0,
                'priority'        => 'normal',
                'status'          => 'open',
                'due_date'        => $next_week,
                'manager_id'      => $user_ids[ CMMS_Auth::ROLE_MANAGER ] ?? 0,
                'assigned_to'     => $user_ids[ CMMS_Auth::ROLE_MANAGER ] ?? 0,
                'recurrence_type' => 'every_x',
                'recurrence_interval' => 90,
            ),
            array(
                'title'           => 'Update workshop tool inventory',
                'description'     => 'General task - reconcile physical tool count vs registry.',
                'category_id'     => $cat( 'general' ),
                'asset_id'        => 0,
                'priority'        => 'low',
                'status'          => 'open',
                'due_date'        => $next_week,
                'manager_id'      => $user_ids[ CMMS_Auth::ROLE_OWNER ] ?? 0,
                'assigned_to'     => $user_ids[ CMMS_Auth::ROLE_TECHNICIAN ] ?? 0,
                'recurrence_type' => 'one_time',
            ),
        );
        foreach ( $tasks as $task ) {
            $task['created_by'] = $user_ids[ CMMS_Auth::ROLE_REPORTER ] ?? $user_ids[ CMMS_Auth::ROLE_OWNER ];
            CMMS_Tasks::create( $account_id, $task );
        }

        // 6) Public report form.
        $form_id = CMMS_Forms::create_form( $account_id, array(
            'name'                => 'Report a Problem',
            'description'         => 'Use this form to report any issue. We will get back to you shortly.',
            'manager_id'          => $user_ids[ CMMS_Auth::ROLE_MANAGER ] ?? 0,
            'default_category_id' => $cat( 'breakdown' ),
            'default_priority'    => 'normal',
            'default_status'      => 'open',
            'created_by'          => $user_ids[ CMMS_Auth::ROLE_OWNER ],
        ) );
        if ( $form_id && ! is_wp_error( $form_id ) ) {
            CMMS_Forms::add_field( $form_id, array( 'label' => 'Brief description',     'field_type' => 'text',     'required' => 1, 'sort_order' => 1 ) );
            CMMS_Forms::add_field( $form_id, array( 'label' => 'Where is the problem?', 'field_type' => 'text',     'required' => 1, 'sort_order' => 2 ) );
            CMMS_Forms::add_field( $form_id, array( 'label' => 'How urgent is it?',     'field_type' => 'dropdown', 'options' => 'Low, Medium, High, Urgent', 'required' => 1, 'sort_order' => 3 ) );
            CMMS_Forms::add_field( $form_id, array( 'label' => 'Additional details',    'field_type' => 'textarea', 'required' => 0, 'sort_order' => 4 ) );
            CMMS_Forms::add_field( $form_id, array( 'label' => 'Photo (optional)',      'field_type' => 'file',     'required' => 0, 'sort_order' => 5 ) );
        }

        return array(
            'account_id'    => $account_id,
            'organization'  => 'Demo Organization' . self::$suffix,
            'login_url'     => home_url( '/cmms-login/' ),
            'dashboard_url' => home_url( '/cmms-dashboard/' ),
            'form_url'      => $form_id ? CMMS_Forms::form_public_url( CMMS_Forms::get( $form_id ) ) : '',
            'password'      => 'demo1234',
            'users'         => array(
                array( 'role' => 'Owner',      'email' => $owner_email ),
                array( 'role' => 'Manager',    'email' => 'manager' . self::$suffix . '@cmms-demo.local' ),
                array( 'role' => 'Technician', 'email' => 'technician' . self::$suffix . '@cmms-demo.local' ),
                array( 'role' => 'Reporter',   'email' => 'reporter' . self::$suffix . '@cmms-demo.local' ),
            ),
        );
    }
}
