<?php namespace ProcessWire;

/**
 * AutoWebP Module
 * WebP + responsive srcset – konfliktfreie API (renderResponsive),
 * Template-/Feld-Defaults, konfigurierbare Klassen, <picture>-Klasse,
 * und NEU: optionale <figure>/<figcaption>-Ausgabe.
 *
 * @author  Dein ...
 * @version 1.4.0
 * @license MIT
 */

class AutoWebP extends WireData implements Module, ConfigurableModule {

    public static function getModuleInfo() {
        return [
            'title' => 'Auto WebP & Responsive Images',
            'version' => '1.4.0',
            'summary' => 'WebP + responsive srcset mit kontextbezogenen Defaults (renderResponsive / renderResponsiveAll) und optionalen <figure>/<figcaption>.',
            'author' => 'Tom Evers',
            'autoload' => true,
            'singular' => true,
            'icon' => 'picture-o',
            'requires' => 'ProcessWire>=3.0.0'
        ];
    }

    /** Defaults */
    public function __construct() {
        // Qualität
        $this->set('webpQuality', 90);
        $this->set('jpegQuality', 90);

        // Responsive
        $this->set('breakpoints', '400, 800, 1200, 1600');
        $this->set('defaultSizes', '(max-width: 768px) 100vw, (max-width: 1200px) 50vw, 33vw');
        $this->set('lazyLoadDefault', 1);
        $this->set('defaultFetchPriority', ''); // '', 'high', 'low', 'auto'

        // Upload
        $this->set('autoConvertOnUpload', 1);

        // Debug
        $this->set('debugComment', 0);

        // Wrapper/Klassen (global)
        $this->set('wrapperTag', 'div');
        $this->set('wrapperClass', 'image-gallery');
        $this->set('itemClass', 'gallery-item');
        $this->set('imageClassDefault', ''); // zusätzliche Klasse auf <img>

        // Figure/Caption (global)
        $this->set('figureDefault', 0);                 // standardmäßig kein <figure>
        $this->set('figureClass', 'figure');
        $this->set('figcaptionClass', 'figure__caption');
        $this->set('figcaptionField', 'description');   // welches Feld am Bild
        $this->set('figcaptionFallback', '');           // '', 'alt', 'basename'

        // API-Aliasse (potenziell kollisionsbehaftet)
        $this->set('useRenderAlias', 0); // render()/renderAll()

        // Mini-Stats
        $this->set('stats', [
            'renderCalls' => 0,
            'imagesAllCalls' => 0,
            'lastReset' => time(),
        ]);

        // Kontext-Defaults (JSON in Config)
        $this->set('templateDefaults', []); // ['templateName' => [..]]
        $this->set('fieldDefaults', []);    // ['fieldName' => [..]]
    }

    /** Init */
    public function init() {
        $config = $this->wire('config');

        // WebP/Resizer Optionen
        $config->webpOptions = array_merge($config->webpOptions ?? [], [
            'quality' => (int) $this->webpQuality
        ]);
        $config->imageSizerOptions = array_merge($config->imageSizerOptions ?? [], [
            'webpAdd' => true,
            'quality' => (int) $this->jpegQuality,
        ]);

        // Breakpoints normalisieren
        $config->imageBreakpoints = $this->normalizeBreakpoints((string) $this->breakpoints);

        // Konfliktfreie, ergonomische API:
        $this->addHookMethod('Pageimage::renderResponsive',  $this, 'hookPageimageRenderResponsive');
        $this->addHookMethod('Pageimages::renderResponsive', $this, 'hookPageimagesRenderResponsive');
        $this->addHookMethod('Pageimages::renderResponsiveAll', $this, 'hookPageimagesRenderResponsiveAll');

        // awp*-Aliasse:
        $this->addHookMethod('Pageimage::awpRender',      $this, 'hookPageimageRenderResponsive');
        $this->addHookMethod('Pageimages::awpRender',     $this, 'hookPageimagesRenderResponsive');
        $this->addHookMethod('Pageimages::awpRenderAll',  $this, 'hookPageimagesRenderResponsiveAll');

        // (Optional) Komfort-Aliasse – können mit anderen Modulen kollidieren
        if ((int) $this->useRenderAlias === 1) {
            $this->addHookMethod('Pageimage::render',     $this, 'hookPageimageRenderResponsive');
            $this->addHookMethod('Pageimages::render',    $this, 'hookPageimagesRenderResponsive');
            $this->addHookMethod('Pageimages::renderAll', $this, 'hookPageimagesRenderResponsiveAll');
        }

        // Auto-Konvertierung bei Upload
        if ((int) $this->autoConvertOnUpload === 1) {
            $this->addHookAfter('InputfieldFile::processInputAddFile', $this, 'hookAutoConvert');
        }
    }

    /* ========================= Hooks ========================= */

    /** Einzelnes Bild */
    public function hookPageimageRenderResponsive(HookEvent $event) {
        /** @var Pageimage $image */
        $image = $event->object;
        $userOptions = $event->arguments(0);
        $userOptions = is_array($userOptions) ? $userOptions : [];

        [$tplName, $fieldName] = $this->detectContextFromImage($image);
        $contextOptions = $this->contextDefaults($tplName, $fieldName);

        $options = $this->mergeItemOptions(array_merge($contextOptions, $userOptions));
        $event->return = $this->renderResponsiveWebp($image, $options);
    }

    /** Erstes Bild aus Pageimages */
    public function hookPageimagesRenderResponsive(HookEvent $event) {
        /** @var Pageimages $images */
        $images = $event->object;
        if (!$images->count()) { $event->return = ''; return; }

        $userOptions = $event->arguments(0);
        $userOptions = is_array($userOptions) ? $userOptions : [];

        [$tplName, $fieldName] = $this->detectContextFromImages($images);
        $contextOptions = $this->contextDefaults($tplName, $fieldName);

        $event->return = $this->renderResponsiveWebp($images->first(), $this->mergeItemOptions(array_merge($contextOptions, $userOptions)));
        $this->bumpStat('renderCalls');
    }

    /** Alle Bilder */
    public function hookPageimagesRenderResponsiveAll(HookEvent $event) {
        /** @var Pageimages $images */
        $images = $event->object;

        $arg = $event->arguments(0);
        $userOptions = is_array($arg) ? $arg : [];

        [$tplName, $fieldName] = $this->detectContextFromImages($images);
        $contextOptions = $this->contextDefaults($tplName, $fieldName);

        // Wrapper-Optionen mit Kontext zusammenführen
        $wrapperTag   = $userOptions['wrapper']      ?? $contextOptions['wrapper']      ?? (string) $this->wrapperTag;
        $wrapperClass = $userOptions['wrapperClass'] ?? $contextOptions['wrapperClass'] ?? (string) $this->wrapperClass;
        $itemClass    = $userOptions['itemClass']    ?? $contextOptions['itemClass']    ?? (string) $this->itemClass;

        if (!$images->count()) { $event->return = ''; return; }

        $itemOptions = $this->mergeItemOptions(array_merge($contextOptions, $userOptions));

        $html = '';
        if ($wrapperTag) {
            $html .= "<{$wrapperTag} class=\"" . $this->escAttr($wrapperClass) . "\">\n";
        }

        foreach ($images as $img) {
            $itemHtml = $this->renderResponsiveWebp($img, $itemOptions);
            if ($itemClass) {
                $html .= "<div class=\"" . $this->escAttr($itemClass) . "\">{$itemHtml}</div>\n";
            } else {
                $html .= $itemHtml . "\n";
            }
            $this->bumpStat('renderCalls');
        }

        if ($wrapperTag) $html .= "</{$wrapperTag}>";
        $this->bumpStat('imagesAllCalls');

        $event->return = $html;
    }

    /** Auto-Konvertierung bei Upload */
    public function hookAutoConvert(HookEvent $event) {
        $file = $event->argumentsByName("pagefile");
        if ($file instanceof Pageimage) {
            try {
                $tmp = $file->webp->url; // Generiert WebP
            } catch (\Throwable $e) {
                $this->wire('log')->save('auto-webp', 'WebP-Generierung bei Upload fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }

    /* ========================= Rendering ========================= */

    protected function renderResponsiveWebp(Pageimage $image, array $options = []) {

        // Alt & Klassen
        $alt   = $this->escAttr($options['alt'] ?: $image->description ?: $image->basename);
        $imgClass = trim(($options['class'] ?? '') . ' ' . (string) $this->imageClassDefault);
        $imgClass = trim($imgClass);
        $pictureClass = trim((string) ($options['pictureClass'] ?? ''));

        // Figure/Caption
        $useFigure       = !empty($options['figure']);
        $figureClass     = trim((string)($options['figureClass'] ?? $this->figureClass));
        $figcaptionClass = trim((string)($options['figcaptionClass'] ?? $this->figcaptionClass));
        $figField        = (string)($options['figcaptionField'] ?? $this->figcaptionField);
        $figFallback     = (string)($options['figcaptionFallback'] ?? $this->figcaptionFallback);

        $lazy  = !empty($options['lazy']);
        $sizes = (string) ($options['sizes'] ?? $this->defaultSizes);
        $bps   = (array)  ($options['breakpoints'] ?? $this->wire('config')->imageBreakpoints);
        $fetch = trim((string) ($options['fetchpriority'] ?? ''));

        // Srcsets generieren (Upscaling verhindern!)
        $webp_srcset = [];
        $fallback_srcset = [];

        foreach ($bps as $width) {
            $width = (int) $width;
            if ($width <= 0) continue;

            if ($image->width > $width) {
                $resized = $image->width($width, [
                    'upscaling' => false, // nie größer als Original
                ]);
                $webp_srcset[]     = "{$resized->webp->url} {$width}w";
                $fallback_srcset[] = "{$resized->url} {$width}w";
            }
        }

        // Original hinzufügen
        $webp_srcset[]     = "{$image->webp->url} {$image->width}w";
        $fallback_srcset[] = "{$image->url} {$image->width}w";

        $webp_srcset_attr     = implode(', ', $webp_srcset);
        $fallback_srcset_attr = implode(', ', $fallback_srcset);

        // <img>-Attribute
        $attrs = [];
        $attrs[] = 'src="' . $this->escAttr($image->url) . '"';
        $attrs[] = 'srcset="' . $this->escAttr($fallback_srcset_attr) . '"';
        $attrs[] = 'sizes="' . $this->escAttr($sizes) . '"';
        $attrs[] = 'alt="' . $alt . '"';
        $attrs[] = 'width="' . (int) $image->width . '"';
        $attrs[] = 'height="' . (int) $image->height . '"';
        if ($lazy) $attrs[] = 'loading="lazy"';
        $attrs[] = 'decoding="async"';
        if (in_array($fetch, ['high','low','auto'], true)) {
            $attrs[] = 'fetchpriority="' . $fetch . '"';
        }
        if ($this->debugComment) {
            $attrs[] = 'data-awp="1"';
            $attrs[] = 'data-awp-bps="' . $this->escAttr(implode(',', $bps)) . '"';
        }
        if ($imgClass) $attrs[] = 'class="' . $this->escAttr($imgClass) . '"';

        // Bild-HTML
        $pictureAttr = $pictureClass ? ' class="' . $this->escAttr($pictureClass) . '"' : '';
        $pictureHtml  = "<picture{$pictureAttr}>\n";
        $pictureHtml .= '    <source srcset="' . $this->escAttr($webp_srcset_attr) . '" type="image/webp" sizes="' . $this->escAttr($sizes) . "\">\n";
        $pictureHtml .= '    <img ' . implode(' ', $attrs) . ">\n";
        $pictureHtml .= "</picture>";

        // Caption ermitteln (nur wenn figure aktiv)
        $captionHtml = '';
        if ($useFigure) {
            $captionText = '';

            if ($figField) {
                // versuche $image->$figField
                $val = $image->get($figField);
                if (is_string($val)) $captionText = trim($val);
            }

            if ($captionText === '' && $figFallback) {
                if ($figFallback === 'alt') {
                    $captionText = html_entity_decode($alt, ENT_QUOTES, 'UTF-8');
                } elseif ($figFallback === 'basename') {
                    $captionText = pathinfo((string) $image->basename, PATHINFO_FILENAME);
                }
            }

            if ($captionText !== '') {
                $captionHtml = '<figcaption class="' . $this->escAttr($figcaptionClass) . '">' . $this->escAttr($captionText) . '</figcaption>';
            }
        }

        // Figure zusammenbauen (optional)
        $html = '';
        if ($this->debugComment) {
            $html .= "\n<!-- AutoWebP: " . ($useFigure ? 'figure + ' : '') . "picture/webp + fallback (bps=" . $this->escAttr(implode(',', $bps)) . ") -->\n";
        }

        if ($useFigure) {
            $figAttr = $figureClass ? ' class="' . $this->escAttr($figureClass) . '"' : '';
            $html .= "<figure{$figAttr}>\n{$pictureHtml}\n{$captionHtml}\n</figure>";
        } else {
            $html .= $pictureHtml;
        }

        return $html;
    }

    /* ========================= Kontext-Erkennung & Defaults ========================= */

    protected function detectContextFromImage(Pageimage $image): array {
        $tplName = '';
        $fieldName = '';
        if ($image->page instanceof Page) {
            $tplName = (string) $image->page->template->name;
        }
        return [$tplName, $fieldName];
    }

    protected function detectContextFromImages($images): array {
        $tplName = '';
        $fieldName = '';

        if (method_exists($images, 'getPage') && $images->getPage() instanceof Page) {
            $tplName = (string) $images->getPage()->template->name;
        } elseif (property_exists($images, 'page') && $images->page instanceof Page) {
            $tplName = (string) $images->page->template->name;
        }

        if (method_exists($images, 'getField') && $images->getField()) {
            $f = $images->getField();
            if ($f instanceof Field) $fieldName = (string) $f->name;
        } elseif (property_exists($images, 'field') && $images->field instanceof Field) {
            $fieldName = (string) $images->field->name;
        }

        return [$tplName, $fieldName];
    }

    protected function contextDefaults(string $tplName, string $fieldName): array {
        $opts = [];
        $tplMap = is_array($this->templateDefaults) ? $this->templateDefaults : [];
        $fldMap = is_array($this->fieldDefaults) ? $this->fieldDefaults : [];

        if ($tplName && isset($tplMap[$tplName]) && is_array($tplMap[$tplName])) {
            $opts = array_merge($opts, $tplMap[$tplName]);
        }
        if ($fieldName && isset($fldMap[$fieldName]) && is_array($fldMap[$fieldName])) {
            $opts = array_merge($opts, $fldMap[$fieldName]);
        }

        // Wenn figure global an ist, aber Kontext es überschreibt, respektieren wir Kontext/Aufruf.
        if (!isset($opts['figure'])) {
            $opts['figure'] = (bool) $this->figureDefault;
        }

        return $opts;
    }

    /* ========================= Helpers ========================= */

    protected function normalizeBreakpoints(string $csv): array {
        $list = array_values(array_filter(array_map(function($v) {
            $v = (int) trim($v);
            return $v > 0 ? $v : null;
        }, explode(',', $csv))));
        sort($list, SORT_NUMERIC);
        return $list;
    }

    protected function mergeItemOptions(array $userOptions): array {
        // Beachte: wrapper*/itemClass werden außerhalb gemerged
        return array_merge([
            'alt' => '',
            'class' => '',
            'pictureClass' => '', // Klasse am <picture>
            'lazy' => (bool) $this->lazyLoadDefault,
            'breakpoints' => $this->wire('config')->imageBreakpoints,
            'sizes' => (string) $this->defaultSizes,
            'fetchpriority' => (string) $this->defaultFetchPriority,

            // Figure/Caption
            'figure' => (bool) $this->figureDefault,
            'figureClass' => (string) $this->figureClass,
            'figcaptionClass' => (string) $this->figcaptionClass,
            'figcaptionField' => (string) $this->figcaptionField,
            'figcaptionFallback' => (string) $this->figcaptionFallback,
        ], $userOptions);
    }

    protected function escAttr($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    protected function bumpStat(string $key): void {
        $stats = $this->stats;
        if (!isset($stats[$key])) $stats[$key] = 0;
        $stats[$key]++;
        $this->set('stats', $stats);

        if ($stats['renderCalls'] % 10 === 0) {
            $this->wire('modules')->saveConfig($this, ['stats' => $stats]);
        }
    }

    /* ========================= Admin Config ========================= */

    public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
        $modules = $this->wire('modules');

        /* Anleitung / Doku */
        $field = $modules->get('InputfieldMarkup');
        $field->label = '📖 Verwendung in Templates';
        $field->value = $this->getUsageInstructions();
        $field->collapsed = Inputfield::collapsedNo;
        $inputfields->add($field);

        /* Mini-Statistik */
        $stats = $this->stats;
        $field = $modules->get('InputfieldMarkup');
        $field->label = '📈 Mini-Statistik';
        $field->value = sprintf(
            '<div style="padding:10px;background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;">
               <b>Render-Aufrufe (Bilder gesamt):</b> %d<br>
               <b>Aufrufe renderResponsiveAll():</b> %d<br>
               <b>Letzter Reset:</b> %s
             </div>
             <p><button name="%s" value="1" class="ui-button ui-widget ui-corner-all">Statistik zurücksetzen</button></p>',
            (int) ($stats['renderCalls'] ?? 0),
            (int) ($stats['imagesAllCalls'] ?? 0),
            date('Y-m-d H:i:s', (int) ($stats['lastReset'] ?? time())),
            'awp_reset_stats'
        );
        $inputfields->add($field);

        if ($this->input->post('awp_reset_stats')) {
            $this->set('stats', ['renderCalls'=>0,'imagesAllCalls'=>0,'lastReset'=>time()]);
            $this->wire('modules')->saveConfig($this, ['stats' => $this->stats]);
            $this->message('AutoWebP: Statistik zurückgesetzt.');
        }

        /* Qualität */
        $f = $modules->get('InputfieldInteger');
        $f->name = 'webpQuality';
        $f->label = 'WebP Qualität';
        $f->description = 'Qualität der WebP-Konvertierung (1–100)';
        $f->value = (int) $this->webpQuality;
        $f->min = 1; $f->max = 100; $f->columnWidth = 50;
        $inputfields->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'jpegQuality';
        $f->label = 'JPEG/PNG Qualität';
        $f->description = 'Qualität der Fallback-Bilder (1–100)';
        $f->value = (int) $this->jpegQuality;
        $f->min = 1; $f->max = 100; $f->columnWidth = 50;
        $inputfields->add($f);

        /* Responsive */
        $f = $modules->get('InputfieldText');
        $f->name = 'breakpoints';
        $f->label = 'Responsive Breakpoints';
        $f->description = 'Kommagetrennte Liste der Bildbreiten (px). Upscaling wird vermieden.';
        $f->notes = 'Standard: 400, 800, 1200, 1600';
        $f->value = (string) $this->breakpoints;
        $inputfields->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'defaultSizes';
        $f->label = 'Standard Sizes-Attribut';
        $f->description = 'Standardwert für das sizes-Attribut (pro Aufruf überschreibbar).';
        $f->value = (string) $this->defaultSizes;
        $inputfields->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'lazyLoadDefault';
        $f->label = 'Lazy Loading standardmäßig aktivieren';
        $f->description = 'Empfehlung: aktiv lassen. Für LCP/Hero-Bilder ggf. pro Aufruf deaktivieren.';
        $f->checked = (bool) $this->lazyLoadDefault;
        $f->columnWidth = 33;
        $inputfields->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'defaultFetchPriority';
        $f->label = 'Default fetchpriority';
        $f->description = 'Für wichtige Hero/LCP-Bilder: „high“. Sonst leer lassen.';
        $f->addOptions(['' => '(leer)', 'high' => 'high', 'low' => 'low', 'auto' => 'auto']);
        $f->value = (string) $this->defaultFetchPriority;
        $f->columnWidth = 33;
        $inputfields->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'autoConvertOnUpload';
        $f->label = 'WebP bei Upload automatisch generieren';
        $f->description = 'WebP-Versionen werden beim Hochladen erzeugt.';
        $f->checked = (bool) $this->autoConvertOnUpload;
        $f->columnWidth = 33;
        $inputfields->add($f);

        /* Wrapper/Klassen (global) */
        $f = $modules->get('InputfieldText');
        $f->name = 'wrapperTag';
        $f->label = 'Wrapper Tag (für renderResponsiveAll())';
        $f->description = 'z. B. div, section, ul … Leer lassen, um keinen Wrapper zu erzeugen.';
        $f->value = (string) $this->wrapperTag;
        $f->columnWidth = 25;
        $inputfields->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'wrapperClass';
        $f->label = 'Wrapper CSS-Klasse';
        $f->value = (string) $this->wrapperClass;
        $f->columnWidth = 25;
        $inputfields->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'itemClass';
        $f->label = 'Item CSS-Klasse';
        $f->value = (string) $this->itemClass;
        $f->columnWidth = 25;
        $inputfields->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'imageClassDefault';
        $f->label = 'Zusätzliche <img>-Klasse (global)';
        $f->description = 'Wird allen Bildern als zusätzliche Klasse mitgegeben.';
        $f->value = (string) $this->imageClassDefault;
        $f->columnWidth = 25;
        $inputfields->add($f);

        /* Figure/Caption (global) */
        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'figureDefault';
        $f->label = '<figure> standardmäßig aktivieren';
        $f->description = 'Wenn aktiv, wird bei renderResponsive()/All ein <figure> um <picture> gerendert (sofern nicht pro Aufruf deaktiviert).';
        $f->checked = (bool) $this->figureDefault;
        $f->columnWidth = 25;
        $inputfields->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'figureClass';
        $f->label = 'Figure CSS-Klasse';
        $f->value = (string) $this->figureClass;
        $f->columnWidth = 25;
        $inputfields->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'figcaptionClass';
        $f->label = 'Figcaption CSS-Klasse';
        $f->value = (string) $this->figcaptionClass;
        $f->columnWidth = 25;
        $inputfields->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'figcaptionField';
        $f->label = 'Standard-Feld für Caption';
        $f->description = 'Welches Pageimage-Feld soll für die Bildunterschrift genutzt werden?';
        // Standard-Auswahl (du kannst hier gern erweitern, z. B. "title" falls du ein eigenes Feld nutzt)
        $f->addOptions([
            'description' => 'description (Standard Bildbeschreibung)',
            'tags'        => 'tags (als Text, erstes Tag)',
            'name'        => 'name (Dateiname)',
        ]);
        $current = in_array($this->figcaptionField, ['description','tags','name'], true) ? $this->figcaptionField : 'description';
        $f->value = $current;
        $f->columnWidth = 25;
        $inputfields->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'figcaptionFallback';
        $f->label = 'Caption-Fallback';
        $f->description = 'Falls das gewählte Feld leer ist: leer lassen, ALT-Text nutzen, oder Dateiname.';
        $f->addOptions(['' => '(kein Fallback)', 'alt' => 'ALT-Text', 'basename' => 'Dateiname (ohne Endung)']);
        $f->value = (string) $this->figcaptionFallback;
        $inputfields->add($f);

        /* Kontext-Defaults (JSON) */
        $tplJsonExample = htmlspecialchars(json_encode([
            "home" => [
                "wrapperClass" => "grid grid-cols-2 md:grid-cols-3 gap-6",
                "itemClass" => "rounded-xl overflow-clip",
                "class" => "shadow-lg",
                "pictureClass" => "block",
                "sizes" => "(max-width: 900px) 100vw, 900px",
                "breakpoints" => [480, 768, 1024, 1440],
                "figure" => true,
                "figureClass" => "figure figure--home",
                "figcaptionField" => "description",
                "figcaptionClass" => "figure__caption text-sm text-muted"
            ]
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'templateDefaults';
        $f->label = 'Template-Defaults (JSON)';
        $f->description = 'Schlüssel = Template-Name. Werte = Optionen (wrapperClass, itemClass, class (=img), pictureClass (=<picture>), sizes, breakpoints, fetchpriority, lazy, wrapper, figure, figureClass, figcaptionField, figcaptionClass, figcaptionFallback).';
        $f->notes = "Beispiel:\n{$tplJsonExample}";
        $f->value = is_array($this->templateDefaults) ? json_encode($this->templateDefaults, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) : (string) $this->templateDefaults;
        $f->rows = 10;
        $inputfields->add($f);

        $fldJsonExample = htmlspecialchars(json_encode([
            "gallery" => [
                "wrapper" => "section",
                "wrapperClass" => "gallery grid grid-cols-3 gap-4",
                "itemClass" => "aspect-square",
                "class" => "rounded-md",
                "pictureClass" => "block",
                "sizes" => "(max-width: 1200px) 100vw, 1200px",
                "figure" => true,
                "figcaptionField" => "description"
            ],
            "hero_image" => [
                "class" => "hero-img",
                "pictureClass" => "hero-picture",
                "lazy"  => false,
                "fetchpriority" => "high",
                "sizes" => "(max-width: 1200px) 100vw, 1200px",
                "breakpoints" => [768, 1200, 1600, 1920],
                "figure" => true,
                "figureClass" => "figure figure--hero",
                "figcaptionFallback" => "alt"
            ]
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'fieldDefaults';
        $f->label = 'Feld-Defaults (JSON)';
        $f->description = 'Schlüssel = Feldname des Image-Feldes (z. B. gallery, hero_image).';
        $f->notes = "Beispiel:\n{$fldJsonExample}";
        $f->value = is_array($this->fieldDefaults) ? json_encode($this->fieldDefaults, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) : (string) $this->fieldDefaults;
        $f->rows = 10;
        $inputfields->add($f);

        /* Debug & Aliasse */
        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'debugComment';
        $f->label = 'Debug-Kommentar & data-Attribute ausgeben';
        $f->description = 'Hilft beim Prüfen im Quelltext (<!-- AutoWebP ... -->).';
        $f->checked = (bool) $this->debugComment;
        $inputfields->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'useRenderAlias';
        $f->label = 'Kompatibilitäts-Aliasse render()/renderAll() aktivieren';
        $f->description = 'Achtung: Kann mit anderen Modulen kollidieren. Empfohlen: deaktiviert lassen und renderResponsive()/renderResponsiveAll() nutzen.';
        $f->checked = (bool) $this->useRenderAlias;
        $inputfields->add($f);

        /* JSON speichern/parsen */
        if ($this->input->post('templateDefaults')) {
            $this->set('templateDefaults', $this->parseJsonArray($this->input->post('templateDefaults'), 'Template-Defaults'));
        }
        if ($this->input->post('fieldDefaults')) {
            $this->set('fieldDefaults', $this->parseJsonArray($this->input->post('fieldDefaults'), 'Feld-Defaults'));
        }

        return $inputfields;
    }

    protected function parseJsonArray($json, string $label): array {
        $arr = [];
        $json = is_array($json) ? json_encode($json) : (string) $json;
        $json = trim($json);
        if ($json === '') return $arr;
        try {
            $tmp = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($tmp)) $arr = $tmp;
        } catch (\Throwable $e) {
            $this->error("AutoWebP: {$label} enthält ungültiges JSON – wurde nicht übernommen. ".$e->getMessage());
        }
        return $arr;
    }

    /** Doku / Hinweise */
    protected function getUsageInstructions() {
        $hintFieldName = '<code>$page-&gt;<b>DEIN_FELDNAME</b>-&gt;renderResponsive()</code>';
        $repeaterExample = htmlspecialchars('<?php foreach($page->buildings as $building): ?>
  <h2><?= $building->title ?></h2>
  <?= $building->photo->renderResponsive([
    "class" => "w-full h-auto",
    "pictureClass" => "block",
    "figure" => true,
    "figureClass" => "figure figure--building",
    "figcaptionField" => "description",
    "figcaptionClass" => "figure__caption"
  ]) ?>
<?php endforeach; ?>', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<style>
.auto-webp-docs{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;line-height:1.6}
.auto-webp-docs h3{color:#0d6efd;margin-top:20px;border-bottom:2px solid #e9ecef;padding-bottom:5px}
.auto-webp-docs code{background:transparent;padding:2px 6px;border-radius:3px;color:#ffffff;font-size:14px}
.auto-webp-docs pre{background:#282c34;color:#abb2bf;padding:15px;border-radius:5px;overflow-x:auto;margin:10px 0}
.auto-webp-docs .info{background:#fff3cd;border:1px solid #ffeeba;padding:10px 12px;border-radius:6px}
</style>

<div class="auto-webp-docs">
  <h3>🚀 Verwendung (konfliktfreie API)</h3>
  <p><b>Einzelnes Bildfeld:</b></p>
  <pre><code>&lt;?= $hintFieldName ?&gt;</code></pre>

  <p><b>Erstes Bild eines Multi-Image-Feldes:</b></p>
  <pre><code>&lt;?= \$page-&gt;<b>DEIN_FELDNAME</b>-&gt;renderResponsive() ?&gt;</code></pre>

  <p><b>Alle Bilder eines Multi-Image-Feldes:</b></p>
  <pre><code>&lt;?= \$page-&gt;<b>DEIN_FELDNAME</b>-&gt;renderResponsiveAll() ?&gt;</code></pre>

  <div class="info">
    Ersetze <b>DEIN_FELDNAME</b> durch den tatsächlichen Namen deines Bildfeldes
    (z.&nbsp;B. <code>hero_image</code>, <code>gallery</code>, ...).
  </div>

  <h3>🎛️ Klassen & Optionen</h3>
  <pre><code>&lt;?= \$page-&gt;hero_image-&gt;renderResponsive([
  'class'        =&gt; 'img-shadow',     // Klasse am &lt;img&gt;
  'pictureClass' =&gt; 'picture-frame',  // Klasse am &lt;picture&gt;
  'sizes'        =&gt; '(max-width: 900px) 100vw, 900px',
  'fetchpriority'=&gt; 'high',
  'lazy'         =&gt; false
]) ?&gt;</code></pre>

  <h3>🧩 Repeater & Figure</h3>
  <pre><code>{$repeaterExample}</code></pre>

  <h3>🧠 Kontext-Defaults</h3>
  <p>In der Modul-Konfiguration kannst du JSON-Maps für <b>Template</b> und <b>Feld</b> hinterlegen (inkl. <code>figure</code>, <code>figureClass</code>, <code>figcaption*</code>).
  Priorität: Aufruf &gt; Feld &gt; Template &gt; global.</p>
</div>
HTML;
    }
}
