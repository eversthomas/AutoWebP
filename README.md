# Auto WebP & Responsive Images for ProcessWire

Automatische WebP-Konvertierung mit **responsive `srcset`**, konfliktfreier API (`renderResponsive*`), optionalem **`<figure>/<figcaption>`**, kontextbezogenen **Template-/Feld-Defaults** und konfigurierbaren Klassen für Wrapper, Items, `<picture>` und `<img>`.

> **Kurz:** Modul installieren → Bildaufrufe in Templates auf `renderResponsive()`/`renderResponsiveAll()` umstellen → fertig.

---

## 🚀 Features

- ✅ **Konfliktfreie API**  
  `Pageimage::renderResponsive()`, `Pageimages::renderResponsive()` (erstes Bild),  
  `Pageimages::renderResponsiveAll()` (alle Bilder)
- ✅ **WebP + Fallback** via `<picture><source type="image/webp"> + <img>`
- ✅ **Responsive Images**: automatisches `srcset` anhand konfigurierbarer Breakpoints (ohne Upscaling)
- ✅ **Performance**: `loading="lazy"`, `decoding="async"`, optional `fetchpriority="high|low|auto"`
- ✅ **Klassensteuerung**: Wrapper, Item, `<picture>` & `<img>` separat – global, per Template/Feld (JSON) oder pro Aufruf
- ✅ **Optionales `<figure>/<figcaption>`** mit wählbarer Caption-Quelle & Fallback
- ✅ **Auto-Konvertierung bei Upload** (WebP-Erzeugung beim Hochladen)
- ✅ **Mini-Statistik** im Backend (Render-Zähler, Reset)
- ✅ **Debug-Kommentar** optional (HTML-Kommentar + `data-`-Attribute)

---

## 🧩 Voraussetzungen

- ProcessWire **3.x**
- PHP mit GD oder Imagick (wie vom ImageSizer genutzt)

---

## ⚙️ Installation

1. Ordner nach **`/site/modules/AutoWebP/`** kopieren  
   (Hauptdatei: `AutoWebP.module.php`)
2. Im Backend → **Modules** → **Refresh** → Modul **installieren**
3. (Optional) Einstellungen im Modul anpassen (Qualität, Breakpoints, Klassen, `<figure>` usw.)
4. Templates anpassen (siehe unten)

> Optional: Aliasse `render()`/`renderAll()` können in der Config aktiviert werden.  
> **Empfohlen:** Nutzung der konfliktfreien API `renderResponsive*`.
