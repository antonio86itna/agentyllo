<?php
// wp eval-file: put the site in paid_ai mode with the mock provider forced.
$general = get_option( 'agyl_settings_general', array() );
$general['operating_mode'] = 'paid_ai';
update_option( 'agyl_settings_general', $general );
update_option( 'agyl_mock_enabled', true );
update_option( 'agyl_mock_reply', '' );
update_option( 'agyl_mock_fail', '' );
update_option( 'agyl_mock_delay_ms', 40 );
delete_option( 'agyl_ai_usage_month' );
echo "mode=paid_ai mock=on\n";
