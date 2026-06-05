<?php
/**
 * Plugin Name: AffiliateCMS AI Vertex AI Integration
 * Plugin URI: https://affiliatecms.com/ai-vertex
 * Description: Extends AffiliateCMS AI plugin to add Google Cloud Vertex AI support without modifying core files.
 * Version: 0.9.1
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: AffiliateCMS
 * Author URI: https://affiliatecms.com
 * License: GPL v2 or later
 */

declare(strict_types=1);

namespace AffiliateCMS\AI\Vertex;

if (!defined('ABSPATH')) {
    exit;
}

// Clear OPcache to ensure code changes are loaded immediately
if (function_exists('opcache_reset')) {
    @opcache_reset();
}

/**
 * Intercept loading of ProviderManager to inject Vertex AI Provider dynamically
 */
spl_autoload_register(function (string $class): void {
    if ($class === 'AffiliateCMS\AI\Providers\ProviderManager') {
        $file = WP_PLUGIN_DIR . '/affiliatecms-ai/src/Providers/ProviderManager.php';
        if (file_exists($file)) {
            $code = file_get_contents($file);
            
            // Rename core class in memory to prevent collision
            $code = preg_replace('/class\s+ProviderManager/', 'class CoreProviderManager', $code);
            // Strip strict types declare to execute eval safely
            $code = str_replace('declare(strict_types=1);', '', $code);
            // Remove php opening tag
            $code = preg_replace('/^<\?php/', '', $code);
            
            // Define CoreProviderManager
            eval($code);

            // Define our overridden ProviderManager extending CoreProviderManager
            eval('namespace AffiliateCMS\AI\Providers;
            class ProviderManager extends CoreProviderManager {
                public function __construct() {
                    parent::__construct();
                    
                    // Trigger loading of ProviderInterface and ProviderResponse first via autoloader
                    interface_exists(ProviderInterface::class);
                    class_exists(ProviderResponse::class);
                    
                    // Register the Vertex AI Provider
                    $provider_file = WP_PLUGIN_DIR . "/affiliatecms-ai-vertex/src/VertexAIProvider.php";
                    if (file_exists($provider_file)) {
                        require_once $provider_file;
                        $this->register(new VertexAIProvider());
                    }
                }
            }');
        }
    }
}, true, true); // Prepend so our loader intercepts it before AffiliateCMS AI's autoloader

/**
 * Intercept Settings API to inject and save Vertex AI configurations
 */
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if ($request->get_route() === '/acms-ai/v1/settings') {
        $data = $response->get_data();
        if (is_array($data)) {
            $data['vertex_ai_project_id'] = get_option('acms_ai_vertex_ai_project_id', '');
            $data['vertex_ai_region']     = get_option('acms_ai_vertex_ai_region', 'us-central1');
            $data['vertex_ai_sa_json']    = get_option('acms_ai_vertex_ai_sa_json', '');
            $response->set_data($data);
        }
    }
    return $response;
}, 10, 3);

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if ($request->get_route() === '/acms-ai/v1/settings' && $request->get_method() === 'POST') {
        $params = $request->get_json_params();
        if (is_array($params)) {
            if (isset($params['vertex_ai_project_id'])) {
                update_option('acms_ai_vertex_ai_project_id', sanitize_text_field($params['vertex_ai_project_id']));
            }
            if (isset($params['vertex_ai_region'])) {
                update_option('acms_ai_vertex_ai_region', sanitize_text_field($params['vertex_ai_region']));
            }
            if (isset($params['vertex_ai_sa_json'])) {
                $sa_json = $params['vertex_ai_sa_json'];
                
                // Validate if it is valid JSON before saving to avoid corruption from sanitize_textarea_field
                $decoded = json_decode($sa_json, true);
                if (is_array($decoded)) {
                    update_option('acms_ai_vertex_ai_sa_json', $sa_json);
                } else {
                    $unslashed = wp_unslash($sa_json);
                    $decoded_unslashed = json_decode($unslashed, true);
                    if (is_array($decoded_unslashed)) {
                        update_option('acms_ai_vertex_ai_sa_json', $unslashed);
                        $sa_json = $unslashed;
                    } else {
                        // Fallback but do not sanitize to avoid breaking JSON structure
                        update_option('acms_ai_vertex_ai_sa_json', $sa_json);
                    }
                }
                
                // Auto-detect project ID if not explicitly specified
                if (empty($params['vertex_ai_project_id'])) {
                    $sa_data = json_decode($sa_json, true);
                    if (is_array($sa_data) && !empty($sa_data['project_id'])) {
                        update_option('acms_ai_vertex_ai_project_id', sanitize_text_field($sa_data['project_id']));
                    }
                }
            }
        }
    }
    return $result;
}, 10, 3);

/**
 * Intercept get_option for Vertex AI API key to return a dummy value
 * if Service Account JSON is configured. This bypasses the core plugin's
 * empty API key check during job execution.
 */
$vertex_api_key_fallback = function ($value) {
    if (empty($value)) {
        $saJson = get_option('acms_ai_vertex_ai_sa_json', '');
        if (!empty($saJson)) {
            return 'service_account_only';
        }
    }
    return $value;
};
add_filter('option_acms_ai_api_key_vertex-ai', $vertex_api_key_fallback);
add_filter('default_option_acms_ai_api_key_vertex-ai', $vertex_api_key_fallback);

/**
 * Output buffering to inject custom settings fields and Javascript helpers into the Admin UI HTML
 */
add_action('current_screen', function ($screen) {
    if ($screen && str_contains($screen->id, 'acms-ai')) {
        ob_start(function ($output) {
            // Javascript helper to intercept and patch Alpine model and data preloading on F5
            $js_helper = '
<script>
(function() {
    // Intercept window.acmsAiPreload to inject custom settings server-side
    if (window.acmsAiPreload && window.acmsAiPreload.settings) {
        window.acmsAiPreload.settings.vertex_ai_project_id = ' . wp_json_encode(get_option('acms_ai_vertex_ai_project_id', '')) . ';
        window.acmsAiPreload.settings.vertex_ai_region = ' . wp_json_encode(get_option('acms_ai_vertex_ai_region', 'us-central1')) . ';
        window.acmsAiPreload.settings.vertex_ai_sa_json = ' . wp_json_encode(get_option('acms_ai_vertex_ai_sa_json', '')) . ';
    }

    // Intercept Alpine component definition
    var originalAiSettingsPage = window.aiSettingsPage;
    if (typeof originalAiSettingsPage === "function") {
        window.aiSettingsPage = function() {
            var component = originalAiSettingsPage();
            
            // Add custom properties to initial state
            component.settings.vertex_ai_project_id = "";
            component.settings.vertex_ai_region = "us-central1";
            component.settings.vertex_ai_sa_json = "";

            // Intercept _applySettings to copy custom keys
            var originalApply = component._applySettings;
            component._applySettings = function(data) {
                originalApply.call(this, data);
                this.settings.vertex_ai_project_id = data.vertex_ai_project_id || "";
                this.settings.vertex_ai_region = data.vertex_ai_region || "us-central1";
                this.settings.vertex_ai_sa_json = data.vertex_ai_sa_json || "";
            };

            // Intercept validateKey to handle saving and dummy API key for Vertex AI
            var originalValidateKey = component.validateKey;
            component.validateKey = async function(providerId) {
                if (providerId === "vertex-ai") {
                    this.validatingKey = providerId;
                    this.keyStatuses[providerId] = null;
                    
                    var localApiFetch = function(endpoint, options) {
                        options = options || {};
                        var method = options.method || "GET";
                        var body = options.body;
                        var config = {
                            method: method,
                            headers: {
                                "X-WP-Nonce": window.acmsAi.nonce,
                                "Content-Type": "application/json",
                            },
                        };
                        if (body) config.body = JSON.stringify(body);
                        return fetch(window.acmsAi.restUrl + endpoint, config).then(function (r) {
                            return r.json().then(function (data) {
                                return { data: data };
                            });
                        });
                    };

                    this.saving = true;
                    try {
                        await localApiFetch("settings", { method: "POST", body: this.settings });
                    } catch (e) {
                        this.saving = false;
                        this.keyStatuses[providerId] = "invalid";
                        this.validatingKey = null;
                        return;
                    }
                    this.saving = false;

                    try {
                        var res = await localApiFetch("validate-key", {
                            method: "POST",
                            body: {
                                provider: providerId,
                                api_key: this.settings.api_keys[providerId] || "service_account_only",
                            },
                        });
                        this.keyStatuses[providerId] = res.data.valid ? "valid" : "invalid";
                    } catch (e) {
                        this.keyStatuses[providerId] = "invalid";
                    }
                    this.validatingKey = null;
                } else {
                    return originalValidateKey.call(this, providerId);
                }
            };

            return component;
        };
    }
})();
</script>';

            $vertex_ai_html = '
                                    <!-- Vertex AI extras -->
                                    <template x-if="p.id === \'vertex-ai\'">
                                        <div class="acms-ai-apikey-vertex-ai" style="margin-top:12px; border-top:1px solid var(--border); padding-top:12px">
                                            <div style="margin-bottom:12px">
                                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px">
                                                    <label class="form-label" style="font-weight:600; font-size:13px; display:block; margin:0">GCP Service Account JSON</label>
                                                    <div>
                                                        <input type="file" x-ref="vertexFileInput" accept=".json" style="display:none"
                                                               @change="
                                                                   const file = $event.target.files[0];
                                                                   if (file) {
                                                                       const reader = new FileReader();
                                                                       reader.onload = (e) => {
                                                                           try {
                                                                               const data = JSON.parse(e.target.result);
                                                                               if (data && data.project_id) {
                                                                                   settings.vertex_ai_sa_json = e.target.result;
                                                                                   settings.vertex_ai_project_id = data.project_id;
                                                                               } else {
                                                                                   alert(\'Invalid GCP Service Account JSON: project_id not found.\');
                                                                               }
                                                                           } catch(err) {
                                                                               alert(\'Invalid JSON file format.\');
                                                                           }
                                                                       };
                                                                       reader.readAsText(file);
                                                                   }
                                                               ">
                                                        <button type="button" class="btn btn-secondary btn-sm" style="display:inline-flex; align-items:center; gap:4px; font-size:11px"
                                                                @click="$refs.vertexFileInput.click()">
                                                            <i class="bi bi-upload"></i> Upload JSON File
                                                        </button>
                                                    </div>
                                                </div>
                                                <textarea class="input" x-model="settings.vertex_ai_sa_json" rows="6" 
                                                          placeholder="Paste the contents of your Service Account JSON file here or use the upload button..." 
                                                          style="width:100%; font-family:monospace; font-size:11px; padding:8px; background:rgba(0,0,0,0.1); border: 1px solid var(--border); color: var(--text); border-radius: 4px;"></textarea>
                                                <span class="form-hint">Required for Service Account authentication. If provided, this JSON is used for OAuth2 token generation instead of the API key above.</span>
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label">GCP Project ID</label>
                                                <input type="text" class="input" x-model="settings.vertex_ai_project_id"
                                                       placeholder="e.g. my-gcp-project-123 (automatically detected from JSON)" style="font-size:12px">
                                            </div>

                                            <div class="form-group" style="margin-top:8px">
                                                <label class="form-label">GCP Region / Location</label>
                                                <input type="text" class="input" x-model="settings.vertex_ai_region"
                                                       placeholder="e.g. us-central1" style="font-size:12px">
                                            </div>
                                        </div>
                                    </template>';

            // Inject the JS helper before the app container
            $output = str_replace(
                '<div id="acms-ai-app"',
                $js_helper . "\n" . '<div id="acms-ai-app"',
                $output
            );

            // Inject the custom HTML fields before Custom Endpoint extras
            $output = str_replace(
                '<!-- Custom Endpoint extras -->',
                $vertex_ai_html . "\n" . '<!-- Custom Endpoint extras -->',
                $output
            );

            return $output;
        });
    }
});

// Initialize GitHub Updater
add_action('admin_init', function () {
    $updater_file = WP_PLUGIN_DIR . '/affiliatecms-ai-vertex/src/GitHubUpdater.php';
    if (file_exists($updater_file)) {
        require_once $updater_file;
        $updater = new GitHubUpdater(
            __FILE__,
            'leluongnghia',
            'affiliatecms-ai-vertex',
            '0.9.1'
        );
        $updater->init();
    }
});

// Add check update link on the plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $check_url = add_query_arg([
        'acms_ai_vertex_check_update' => 1,
        '_wpnonce' => wp_create_nonce('acms_ai_vertex_check_nonce')
    ], admin_url('plugins.php'));
    $links[] = '<a href="' . esc_url($check_url) . '">' . __('Check Update', 'affiliatecms-ai-vertex') . '</a>';
    return $links;
});

// Process the check update request
add_action('admin_init', function () {
    if (isset($_GET['acms_ai_vertex_check_update']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'acms_ai_vertex_check_nonce')) {
        // Clear updates transients to force check
        delete_transient('acms_ai_vertex_github_release');
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache();
        
        // Redirect to updates page
        wp_safe_redirect(admin_url('update-core.php'));
        exit;
    }
});
