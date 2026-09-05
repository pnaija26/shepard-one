<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ConfigurationCategory;
use App\Models\Setting;
use App\Services\ConfigurationService;

class ConfigurationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(ConfigurationService $configurationService)
    {
        // Create system categories
        $configurationService->createCategory('system', 'System-wide configuration settings', 'system.', true);
        $configurationService->createCategory('user', 'User-related settings', 'user.', false);
        $configurationService->createCategory('organization', 'Organization structure settings', 'org.', false);
        $configurationService->createCategory('security', 'Security and authentication settings', 'security.', false);
        
        // Create some sample settings
        $configurationService->set('system_name', 'ShepardOne Church Management System', 'string', 'system', 'Name of the system');
        $configurationService->set('system_version', '1.0.0', 'string', 'system', 'Current version of the system');
        $configurationService->set('maintenance_mode', false, 'boolean', 'system', 'Whether the system is in maintenance mode');
        $configurationService->set('max_login_attempts', 5, 'integer', 'security', 'Maximum login attempts before lockout');
        $configurationService->set('session_timeout_minutes', 30, 'integer', 'security', 'Session timeout in minutes');
        $configurationService->set('enable_user_registration', true, 'boolean', 'user', 'Whether user registration is enabled');
        $configurationService->set('allowed_domains', ['example.com'], 'json', 'security', 'Allowed email domains for registration');

        // Centrally locked HQ setting (Story 1.7 AC1).
        Setting::where('key', 'system_name')->update(['is_locked' => true]);
    }
}