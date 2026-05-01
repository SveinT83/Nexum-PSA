<?php

namespace Database\Seeders;

use App\Models\Doc\DocumentationTemplate;
use App\Models\System\Category;
use Illuminate\Database\Seeder;

class DocumentationTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Restores default documentation templatesManagement for various categories.
     */
    public function run(): void
    {
        $templates = [
            'LAN' => [
                'name' => 'Standard LAN Setup',
                'fields' => [
                    ['layout' => 'rowStart', 'labelName' => 'Network Configuration'],
                    ['Name' => 'gateway', 'labelName' => 'Gateway IP', 'type' => 'text'],
                    ['Name' => 'subnet_mask', 'labelName' => 'Subnet Mask', 'type' => 'text'],
                    ['Name' => 'dns_1', 'labelName' => 'DNS 1', 'type' => 'text'],
                    ['Name' => 'dns_2', 'labelName' => 'DNS 2', 'type' => 'text'],
                    ['layout' => 'rowStart', 'labelName' => 'VLANs & Routing'],
                    ['Name' => 'vlans', 'labelName' => 'Active VLANs', 'type' => 'textarea'],
                    ['Name' => 'routing_notes', 'labelName' => 'Routing Notes', 'type' => 'textarea'],
                    ['layout' => 'rowStart', 'labelName' => 'Hardware'],
                    ['Name' => 'switch_model', 'labelName' => 'Main Switch Model', 'type' => 'text'],
                    ['Name' => 'serial_number', 'labelName' => 'Serial Number', 'type' => 'text'],
                    ['Name' => 'managed', 'labelName' => 'Is Managed?', 'type' => 'checkbox'],
                ]
            ],
            'Internet/WAN' => [
                'name' => 'Internet Connection Details',
                'fields' => [
                    ['layout' => 'rowStart', 'labelName' => 'Provider Details'],
                    ['Name' => 'isp_name', 'labelName' => 'ISP Name', 'type' => 'text'],
                    ['Name' => 'account_number', 'labelName' => 'Account Number', 'type' => 'text'],
                    ['Name' => 'support_phone', 'labelName' => 'Support Phone', 'type' => 'text'],
                    ['layout' => 'rowStart', 'labelName' => 'Technical Specs'],
                    ['Name' => 'wan_ip', 'labelName' => 'WAN IP Address', 'type' => 'text'],
                    ['Name' => 'connection_type', 'labelName' => 'Connection Type', 'type' => 'select', 'options' => [
                        'fiber' => 'Fiber',
                        'coax' => 'Coax/Cable',
                        'dsl' => 'DSL',
                        'radio' => 'Radio/Wireless',
                        '4g5g' => '4G/5G'
                    ]],
                    ['Name' => 'bandwidth_down', 'labelName' => 'Download Speed (Mbps)', 'type' => 'text'],
                    ['Name' => 'bandwidth_up', 'labelName' => 'Upload Speed (Mbps)', 'type' => 'text'],
                    ['layout' => 'rowStart', 'labelName' => 'Modem/CPE'],
                    ['Name' => 'modem_model', 'labelName' => 'Modem Model', 'type' => 'text'],
                    ['Name' => 'bridge_mode', 'labelName' => 'Bridge Mode Enabled', 'type' => 'checkbox'],
                ]
            ],
            'Wireless' => [
                'name' => 'WiFi Configuration',
                'fields' => [
                    ['layout' => 'rowStart', 'labelName' => 'SSID Configuration'],
                    ['Name' => 'ssid_main', 'labelName' => 'Main SSID', 'type' => 'text'],
                    ['Name' => 'psk_main', 'labelName' => 'Main Password', 'type' => 'text'],
                    ['Name' => 'ssid_guest', 'labelName' => 'Guest SSID', 'type' => 'text'],
                    ['Name' => 'psk_guest', 'labelName' => 'Guest Password', 'type' => 'text'],
                    ['layout' => 'rowStart', 'labelName' => 'Infrastructure'],
                    ['Name' => 'controller_ip', 'labelName' => 'Controller IP', 'type' => 'text'],
                    ['Name' => 'ap_count', 'labelName' => 'Number of APs', 'type' => 'text'],
                    ['Name' => 'ap_models', 'labelName' => 'AP Models', 'type' => 'textarea'],
                    ['layout' => 'rowStart', 'labelName' => 'Security'],
                    ['Name' => 'encryption', 'labelName' => 'Encryption Type', 'type' => 'select', 'options' => [
                        'wpa2_psk' => 'WPA2 Personal',
                        'wpa3_psk' => 'WPA3 Personal',
                        'wpa2_ent' => 'WPA2 Enterprise',
                        'wpa3_ent' => 'WPA3 Enterprise'
                    ]],
                    ['Name' => 'radius_server', 'labelName' => 'RADIUS Server (if applicable)', 'type' => 'text'],
                ]
            ],
            'Backup' => [
                'name' => 'Backup Policy & Config',
                'fields' => [
                    ['layout' => 'rowStart', 'labelName' => 'Backup Strategy'],
                    ['Name' => 'backup_software', 'labelName' => 'Backup Software', 'type' => 'text'],
                    ['Name' => 'backup_type', 'labelName' => 'Backup Type', 'type' => 'select', 'options' => [
                        'cloud' => 'Cloud Only',
                        'local' => 'Local Only',
                        'hybrid' => 'Hybrid (Local + Cloud)'
                    ]],
                    ['layout' => 'rowStart', 'labelName' => 'Targets & Schedule'],
                    ['Name' => 'local_target', 'labelName' => 'Local Target (e.g. NAS)', 'type' => 'text'],
                    ['Name' => 'cloud_target', 'labelName' => 'Cloud Target', 'type' => 'text'],
                    ['Name' => 'schedule', 'labelName' => 'Backup Schedule', 'type' => 'text'],
                    ['layout' => 'rowStart', 'labelName' => 'Verification'],
                    ['Name' => 'last_test_restore', 'labelName' => 'Last Test Restore', 'type' => 'date'],
                    ['Name' => 'monitoring_enabled', 'labelName' => 'Backup Monitoring Enabled', 'type' => 'checkbox'],
                    ['Name' => 'notes', 'labelName' => 'Backup Notes', 'type' => 'textarea'],
                ]
            ],
            'Email' => [
                'name' => 'Email system Documentation',
                'fields' => [
                    ['layout' => 'rowStart', 'labelName' => 'Platform'],
                    ['Name' => 'platform', 'labelName' => 'Email Platform', 'type' => 'select', 'options' => [
                        'm365' => 'Microsoft 365',
                        'google' => 'Google Workspace',
                        'exchange' => 'On-Prem Exchange',
                        'imap' => 'IMAP/POP3'
                    ]],
                    ['Name' => 'tenant_id', 'labelName' => 'Tenant ID / Domain', 'type' => 'text'],
                    ['layout' => 'rowStart', 'labelName' => 'Security & Records'],
                    ['Name' => 'spf_record', 'labelName' => 'SPF Record', 'type' => 'text'],
                    ['Name' => 'dkim_enabled', 'labelName' => 'DKIM Enabled', 'type' => 'checkbox'],
                    ['Name' => 'dmarc_policy', 'labelName' => 'DMARC Policy', 'type' => 'text'],
                    ['layout' => 'rowStart', 'labelName' => 'Migration/History'],
                    ['Name' => 'migration_date', 'labelName' => 'Migration Date', 'type' => 'date'],
                    ['Name' => 'old_system', 'labelName' => 'Previous system', 'type' => 'text'],
                ]
            ],
            'Printing' => [
                'name' => 'Printer / MFP Setup',
                'fields' => [
                    ['layout' => 'rowStart', 'labelName' => 'Device Details'],
                    ['Name' => 'printer_name', 'labelName' => 'Printer Name', 'type' => 'text'],
                    ['Name' => 'model', 'labelName' => 'Model', 'type' => 'text'],
                    ['Name' => 'ip_address', 'labelName' => 'IP Address', 'type' => 'text'],
                    ['Name' => 'serial_number', 'labelName' => 'Serial Number', 'type' => 'text'],
                    ['layout' => 'rowStart', 'labelName' => 'Configuration'],
                    ['Name' => 'print_server', 'labelName' => 'Print Server', 'type' => 'text'],
                    ['Name' => 'driver_path', 'labelName' => 'Driver Path/Link', 'type' => 'text'],
                    ['Name' => 'scan_to_email', 'labelName' => 'Scan-to-Email Configured', 'type' => 'checkbox'],
                    ['Name' => 'scan_to_folder', 'labelName' => 'Scan-to-Folder Configured', 'type' => 'checkbox'],
                    ['layout' => 'rowStart', 'labelName' => 'Maintenance'],
                    ['Name' => 'vendor_contact', 'labelName' => 'Service Vendor', 'type' => 'text'],
                    ['Name' => 'toner_model', 'labelName' => 'Toner Model', 'type' => 'text'],
                ]
            ],
            'Remote Access' => [
                'name' => 'VPN & Remote Access',
                'fields' => [
                    ['layout' => 'rowStart', 'labelName' => 'VPN Configuration'],
                    ['Name' => 'vpn_type', 'labelName' => 'VPN Type', 'type' => 'select', 'options' => [
                        'ssl_vpn' => 'SSL VPN',
                        'ipsec' => 'IPsec / IKEv2',
                        'wireguard' => 'WireGuard',
                        'openvpn' => 'OpenVPN'
                    ]],
                    ['Name' => 'server_address', 'labelName' => 'VPN Gateway Address', 'type' => 'text'],
                    ['Name' => 'port', 'labelName' => 'Port', 'type' => 'text'],
                    ['layout' => 'rowStart', 'labelName' => 'Authentication'],
                    ['Name' => 'auth_method', 'labelName' => 'Auth Method', 'type' => 'select', 'options' => [
                        'local' => 'Local User',
                        'ad' => 'Active Directory / LDAP',
                        'radius' => 'RADIUS',
                        'saml' => 'SAML / SSO'
                    ]],
                    ['Name' => 'mfa_enabled', 'labelName' => 'MFA Enabled', 'type' => 'checkbox'],
                    ['layout' => 'rowStart', 'labelName' => 'Client Software'],
                    ['Name' => 'software_name', 'labelName' => 'Client Software Name', 'type' => 'text'],
                    ['Name' => 'download_url', 'labelName' => 'Download URL', 'type' => 'text'],
                ]
            ],
        ];

        foreach ($templates as $categorySlug => $templateData) {
            $category = Category::where('slug', $categorySlug)->first();

            if ($category) {
                DocumentationTemplate::updateOrCreate(
                    ['category_id' => $category->id],
                    [
                        'name' => $templateData['name'],
                        'fields' => $templateData['fields'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
