<?php

declare(strict_types=1);

namespace AffiliateCMS\AI\Vertex;

use stdClass;

class GitHubUpdater
{
    private string $file;
    private string $slug;
    private string $basename;
    private string $username;
    private string $repo;
    private string $version;

    public function __construct(string $file, string $username, string $repo, string $version)
    {
        $this->file = $file;
        $this->basename = plugin_basename($file);
        $this->slug = dirname($this->basename);
        $this->username = $username;
        $this->repo = $repo;
        $this->version = $version;
    }

    public function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'postInstall'], 10, 3);
    }

    public function checkUpdate($transient)
    {
        if (empty($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->getLatestRelease();
        if (!$release) {
            return $transient;
        }

        $newVersion = ltrim($release['tag_name'], 'v');

        if (version_compare($newVersion, $this->version, '>')) {
            $item = new stdClass();
            $item->id = $this->basename;
            $item->slug = $this->slug;
            $item->plugin = $this->basename;
            $item->new_version = $newVersion;
            $item->url = "https://github.com/{$this->username}/{$this->repo}";
            $item->package = $release['zipball_url'];

            $transient->response[$this->basename] = $item;
        }

        return $transient;
    }

    public function pluginInfo($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->slug) {
            return $result;
        }

        $release = $this->getLatestRelease();
        if (!$release) {
            return $result;
        }

        $info = new stdClass();
        $info->name = 'AffiliateCMS AI Vertex AI Integration';
        $info->slug = $this->slug;
        $info->version = ltrim($release['tag_name'], 'v');
        $info->author = 'AffiliateCMS';
        $info->homepage = "https://github.com/{$this->username}/{$this->repo}";
        $info->download_link = $release['zipball_url'];
        $info->sections = [
            'description' => '<p>Vertex AI integration for AffiliateCMS AI. Features auto-updates via GitHub.</p>',
            'changelog' => '<p>' . nl2br(esc_html($release['body'] ?? 'No changelog provided.')) . '</p>',
        ];

        return $info;
    }

    public function postInstall($true, $hook_extra, $result)
    {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $pluginFolder = WP_PLUGIN_DIR . '/' . $this->slug;
        $destination = $result['destination'] ?? '';

        if (!empty($destination) && basename($destination) !== $this->slug) {
            $wp_filesystem->move($destination, $pluginFolder);
            $result['destination'] = $pluginFolder;
        }

        return $result;
    }

    private function getLatestRelease(): ?array
    {
        $cacheKey = 'acms_ai_vertex_github_release';
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $url = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases/latest";
        $response = wp_remote_get($url, [
            'headers' => [
                'User-Agent' => 'WordPress-GitHub-Updater',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        set_transient($cacheKey, $data, 12 * HOUR_IN_SECONDS);

        return $data;
    }
}
