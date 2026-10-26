<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

class WebsiteStaticRenderer
{
    public const VERSION = 'website-static-v1';

    public const PROFILE = 'review-noindex-v1';

    private Factory $views;

    private string $css;

    private string $script;

    public function __construct(private WebsiteArtifactBudget $budget)
    {
        // Isolated factory: no application shared data, composers, session, auth, Vite or app layout.
        $files = new Filesystem;
        $compiler = new BladeCompiler($files, storage_path('framework/views'));
        $finder = new FileViewFinder($files, [resource_path('views/website-static')]);
        $programs = [];
        foreach (['page', 'partials.card'] as $name) {
            $path = $finder->find($name); // Prime the finder once, before any rendering.
            $compiled = $compiler->compileString(self::resource($path));
            // Compile fixed repository Blade only. DTO strings are arguments, never PHP source.
            $programs[$path] = eval('return static function(array $__data): void { extract($__data, EXTR_SKIP); ?>'.$compiled.'<?php };');
        }
        $resolver = new EngineResolver;
        $resolver->register('blade', fn () => new WebsiteStaticViewEngine($programs));
        $this->views = new Factory($resolver, $finder, new Dispatcher);
        $this->css = self::resource(resource_path('website-static/site.css'));
        $this->script = self::resource(resource_path('website-static/site.js'));
    }

    /** @return array{version:string,digest:string,profile:string,profile_digest:string} */
    public function recipe(): array
    {
        $paths = [...(glob(resource_path('views/website-static/*.blade.php')) ?: []), ...(glob(resource_path('views/website-static/partials/*.blade.php')) ?: []), ...(glob(resource_path('website-static/*')) ?: []), __FILE__, __DIR__.'/WebsiteOutputProfile.php', __DIR__.'/WebsiteStaticViewEngine.php'];
        sort($paths);
        $hashes = array_map(fn ($path) => hash_file('sha256', $path), $paths);

        return ['version' => self::VERSION, 'digest' => hash('sha256', WebsiteReleaseJson::encode($hashes)), 'profile' => self::PROFILE, 'profile_digest' => hash('sha256', WebsiteReleaseJson::encode([WebsiteOutputProfile::frozen(), self::PROFILE]))];
    }

    /** @param array<string,mixed> $dto
     * @return array<string,array{bytes:string,mime:string}> */
    public function render(array $dto): array
    {
        $files = [];
        $started = microtime(true);
        $logicalBytes = 0;
        $put = function (string $path, string $bytes, string $mime) use (&$files, &$logicalBytes, $started): void {
            if (isset($files[$path]) || ! self::safePath($path)) {
                throw new WebsiteArtifactFailure('paths');
            }
            $bytes = str_replace("\r\n", "\n", $bytes);
            $logicalBytes += strlen($bytes);
            $this->budget->fileCount(count($files) + 1);
            $this->budget->bytes($logicalBytes);
            $this->budget->checkpoint($started);
            $files[$path] = ['bytes' => $bytes, 'mime' => $mime];
        };
        $urls = [];
        $serviceUrls = [];
        foreach (['en', 'bm'] as $language) {
            $prefix = $language === 'bm' ? 'bm/' : '';
            $otherPrefix = $language === 'bm' ? '' : 'bm/';
            $grouped = [];
            foreach ($dto['items'][$language] as $item) {
                $grouped[$item['type']][] = $item['content'];
            }
            $routes = [];
            foreach ($dto['pages'] as $page) {
                $routes[$page] = $prefix.($page === 'home' ? '' : $page.'/').'index.html';
            }
            $pages = array_map(fn ($page) => ['type' => $page, 'route' => $routes[$page], 'branch' => null], $dto['pages']);
            if (isset($routes['branches'])) {
                foreach ($grouped['branch_public_profile'] ?? [] as $branch) {
                    $pages[] = ['type' => 'branch', 'route' => $prefix.$branch['alias'].'/index.html', 'branch' => $branch];
                }
            }
            if (isset($routes['services'])) {
                $pages[] = ['type' => 'service-detail', 'route' => $prefix.'service-detail/index.html', 'branch' => null];
                foreach ($grouped['public_service'] ?? [] as $service) {
                    $serviceUrls[] = $dto['origin'].'/'.$prefix.'service-detail/?slug='.rawurlencode($service['alias']);
                }
            }
            $pages[] = ['type' => '404', 'route' => $prefix.'404.html', 'branch' => null];
            foreach ($pages as $page) {
                $route = $page['route'];
                $type = $page['type'];
                $path = self::publicPath($route);
                $alternate = $otherPrefix.substr($path, strlen($prefix));
                $title = match ($type) {
                    'branch' => $page['branch']['display_name'],'service-detail' => $language === 'en' ? 'Service details' : 'Butiran perkhidmatan','404' => $language === 'en' ? 'Page unavailable' : 'Halaman tidak tersedia',default => $dto['navigation'][$type][$language]
                };
                $description = $language === 'en' ? $title.' — '.WebsiteOutputProfile::WORDMARK.'. Clinic information.' : $title.' — '.WebsiteOutputProfile::WORDMARK.'. Maklumat klinik.';
                $relative = fn (string $to) => self::relative($route, $to);
                $data = ['site' => $dto, 'language' => $language, 'htmlLanguage' => $language === 'en' ? 'en' : 'ms', 'type' => $type, 'title' => $title, 'description' => $description, 'route' => $route, 'canonical' => $dto['origin'].'/'.$path, 'alternate' => $dto['origin'].'/'.$alternate, 'switchUrl' => $relative($alternate === '' ? 'index.html' : (str_ends_with($alternate, '.html') ? $alternate : $alternate.'index.html')), 'routes' => $routes, 'groups' => $grouped, 'branch' => $page['branch'], 'relative' => $relative, 'wordmark' => WebsiteOutputProfile::WORDMARK, 'homeLine' => WebsiteOutputProfile::HOME_LINE[$language]];
                $put($route, $this->views->make('page', $data)->render(), 'text/html');
                if ($type !== '404' && $type !== 'service-detail') {
                    $urls[] = $dto['origin'].'/'.$path;
                }
            }
            if (isset($routes['services'])) {
                $json = ['services' => $grouped['public_service'] ?? [], 'categories' => $grouped['service_category'] ?? []];
                $put('data/services'.($language === 'bm' ? '.bm' : '').'.json', json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)."\n", 'application/json');
            }
        }
        $css = $this->css;
        foreach ($dto['design']['tokens'] as $token => $value) {
            $css = str_replace('__'.$token.'__', $value, $css);
        }
        $css = str_replace('__font__', $dto['design']['font'], $css);
        $put('assets/site.css', $css, 'text/css');
        $put('assets/site.js', $this->script, 'text/javascript');
        foreach ($dto['images'] as $path => $image) {
            if (isset($files[$path]) || ! self::safePath($path)) {
                throw new WebsiteArtifactFailure('paths');
            }
            $logicalBytes += strlen($image['bytes']);
            $this->budget->fileCount(count($files) + 1);
            $this->budget->bytes($logicalBytes);
            $this->budget->checkpoint($started);
            // Binary originals must never be normalised or re-encoded.
            $files[$path] = ['bytes' => $image['bytes'], 'mime' => $image['mime']];
        }
        $put('sitemap.xml', self::sitemap($urls), 'application/xml');
        $put('sitemap-services.xml', self::sitemap($serviceUrls), 'application/xml');
        $put('robots.txt', "User-agent: *\nDisallow: /\n", 'text/plain');
        $put('_headers', "/*\n  Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'\n  X-Robots-Tag: noindex, nofollow\n  X-Content-Type-Options: nosniff\n  Referrer-Policy: no-referrer\n  Permissions-Policy: camera=(), microphone=(), geolocation=()\n", 'text/plain');
        ksort($files);

        return $files;
    }

    private static function resource(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new WebsiteArtifactFailure('render');
        }

        return $bytes;
    }

    public static function safePath(string $path): bool
    {
        return $path === '_headers' || (bool) preg_match('~\A(?:[a-z0-9][a-z0-9-]*/)*(?:[a-z0-9][a-z0-9-]*(?:\.bm)?\.(?:html|css|js|json|xml|txt)|[a-f0-9]{64}\.(?:jpg|png|webp))\z~', $path);
    }

    public static function publicPath(string $file): string
    {
        return str_ends_with($file, 'index.html') ? substr($file, 0, -10) : $file;
    }

    public static function relative(string $from, string $to): string
    {
        $depth = dirname($from) === '.' ? 0 : count(explode('/', dirname($from)));
        $path = self::publicPath($to);

        return str_repeat('../', $depth).($path === '' ? './' : $path);
    }

    /** @param list<string> $urls */
    private static function sitemap(array $urls): string
    {
        $urls = array_values(array_unique($urls));
        sort($urls);
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $root = $xml->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
        $xml->appendChild($root);
        foreach ($urls as $url) {
            $entry = $xml->createElement('url');
            $loc = $xml->createElement('loc');
            $loc->appendChild($xml->createTextNode($url));
            $entry->appendChild($loc);
            $root->appendChild($entry);
        }

        $result = $xml->saveXML();
        if ($result === false) {
            throw new WebsiteArtifactFailure('render');
        }

        return $result;
    }
}
