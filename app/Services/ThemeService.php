<?php

namespace App\Services;

use App\Support\AtomicConfigWriter;
use App\Support\ConfiguredUrl;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class ThemeService
{
    private $path;
    private $theme;

    public function __construct($theme)
    {
        $theme = (string)$theme;
        if (strlen($theme) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/D', $theme)) {
            throw new \InvalidArgumentException('主题名称无效');
        }

        $this->theme = $theme;
        $this->path = public_path('theme') . DIRECTORY_SEPARATOR;
    }

    public function init()
    {
        $data = [];
        foreach ($this->validatedDefinitions() as $definition) {
            $data[$definition['field_name']] = array_key_exists('default_value', $definition)
                ? $definition['default_value']
                : '';
        }

        try {
            return $this->save($data);
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException("{$this->theme}主题默认配置无效", 0, $error);
        }
    }

    public function definition()
    {
        $themeConfigFile = $this->path . $this->theme . DIRECTORY_SEPARATOR . 'config.json';
        if (!File::isFile($themeConfigFile)) {
            throw new \RuntimeException("{$this->theme}主题不存在");
        }

        try {
            $contents = File::get($themeConfigFile);
        } catch (\Throwable $error) {
            throw new \RuntimeException("{$this->theme}主题配置文件不可读", 0, $error);
        }

        $themeConfig = json_decode($contents, true, 64);
        if (json_last_error() !== JSON_ERROR_NONE
            || !is_array($themeConfig)
            || !isset($themeConfig['configs'])
            || !is_array($themeConfig['configs'])
            || count($themeConfig['configs']) > 256
        ) {
            throw new \RuntimeException("{$this->theme}主题配置文件有误");
        }

        return $themeConfig;
    }

    public function save(array $data)
    {
        $normalized = [];
        foreach ($this->validatedDefinitions() as $definition) {
            $field = $definition['field_name'];
            $value = array_key_exists($field, $data) ? $data[$field] : '';
            $normalized[$field] = $this->normalizeValue($definition, $value);
        }

        $path = base_path('config/theme') . DIRECTORY_SEPARATOR . $this->theme . '.php';
        $contents = "<?php\n\nreturn " . var_export($normalized, true) . ";\n";
        AtomicConfigWriter::write($path, $contents);

        // config:cache runs in a fresh application instance; refresh this request explicitly.
        Config::set('theme.' . $this->theme, $normalized);

        return $normalized;
    }

    private function validatedDefinitions()
    {
        $definitions = $this->definition()['configs'];
        $validated = [];
        $fields = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)
                || !isset($definition['field_name'])
                || !is_string($definition['field_name'])
                || strlen($definition['field_name']) > 64
                || !preg_match('/^[A-Za-z0-9_-]+$/D', $definition['field_name'])
            ) {
                throw new \RuntimeException("{$this->theme}主题配置字段有误");
            }

            $field = $definition['field_name'];
            if (isset($fields[$field])) {
                throw new \RuntimeException("{$this->theme}主题配置字段重复");
            }
            $fields[$field] = true;

            if (($definition['field_type'] ?? null) === 'select') {
                if (!isset($definition['select_options'])
                    || !is_array($definition['select_options'])
                    || count($definition['select_options']) > 256
                ) {
                    throw new \RuntimeException("{$this->theme}主题选择项配置有误");
                }
            }

            $validated[] = $definition;
        }

        return $validated;
    }

    private function normalizeValue(array $definition, $value)
    {
        if ($value === null) {
            $value = '';
        }
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('主题配置值必须是标量');
        }

        $value = (string)$value;
        if (strlen($value) > 262144) {
            throw new \InvalidArgumentException('主题配置值过长');
        }

        if ($definition['field_name'] === 'background_url' && $value !== '') {
            $value = ConfiguredUrl::normalizeExternalHttpUrl($value);
            if ($value === '') {
                throw new \InvalidArgumentException('主题背景必须是无认证信息的 HTTP(S) 地址');
            }
        }

        if (($definition['field_type'] ?? null) === 'select'
            && !array_key_exists($value, $definition['select_options'])
        ) {
            throw new \InvalidArgumentException('主题选择项无效');
        }

        return $value;
    }
}
