# Krystian Grzyb Fotografia

Strona wizytówka fotografa okolicznościowego Krystiana Grzyba. Specjalizacja: osiemnastki, andrzejki i imprezy okolicznościowe. Ciemny, kinowy portfolio one-pager z galerią zdjęć, formularzem kontaktowym i pełną warstwą SEO.

## Stack

- Node.js + Express (serwer, dynamiczne sitemap.xml, robots.txt, nagłówki bezpieczeństwa)
- Statyczne HTML / CSS / JavaScript (bez frameworków)
- Fonty: Bodoni Moda + Manrope (Google Fonts)
- Formularz: web3forms (bez własnego backendu)
- Obrazy: WebP (sharp)
- Deploy: Railway

## Uruchomienie lokalne

```bash
npm install
npm run build:og        # generuje og-image.png oraz og-image.jpg ze og-image.svg
npm start               # serwer na http://localhost:3000
```

Audyt wizualny (Playwright):

```bash
AUDIT_PORT=3789 PORT=3789 node server.js &   # albo w osobnym terminalu
npm run screenshot
```

## Zmienne środowiskowe

| Zmienna    | Opis                                                        |
|------------|-------------------------------------------------------------|
| `PORT`     | Port serwera (domyślnie 3000, Railway ustawia automatycznie) |
| `SITE_URL` | Pełny adres produkcyjny, np. https://krystiangrzyb.pl. Używany w canonical, sitemap, OG. Jeśli pusty, adres jest budowany z nagłówków żądania. |

## Co podmienić przed publikacją

Numer telefonu (570 941 162) i klucz formularza web3forms są już wpisane. Działalność nierejestrowana, więc strona nie zawiera NIP, REGON ani wpisu w CEIDG. Zostaje:

| Co                          | Gdzie                                                                 |
|-----------------------------|-----------------------------------------------------------------------|
| Kod Google Search Console   | wszystkie pliki HTML, `GOOGLE_SEARCH_CONSOLE_VERIFICATION_PLACEHOLDER` |
| E-mail                      | `kontakt@krystiangrzyb.pl` (jeśli ma być inny, podmień w plikach HTML) |
| Opinie Google (opcjonalnie) | sekcja Opinie linkuje do profilu Google; aby pokazać karty opinii na stronie, dodaj widżet lub prześlij treść opinii |

## Formularz kontaktowy (web3forms)

1. Załóż darmowe konto na [web3forms.com](https://web3forms.com).
2. Skopiuj swój Access Key.
3. W `public/index.html` zamień `WEB3FORMS_ACCESS_KEY_PLACEHOLDER` na ten klucz.
4. Zgłoszenia trafią na e-mail podany przy zakładaniu konta web3forms (może to być Twój Gmail).

## Google Search Console

1. Dodaj właściwość w [search.google.com/search-console](https://search.google.com/search-console).
2. Wybierz weryfikację przez tag HTML i skopiuj wartość `content`.
3. Zamień `GOOGLE_SEARCH_CONSOLE_VERIFICATION_PLACEHOLDER` we wszystkich plikach HTML.
4. Wdróż, kliknij Zweryfikuj, a potem wyślij mapę `/sitemap.xml`.

## Deploy (Railway)

Repozytorium podłączone do projektu Railway buduje się automatycznie po `git push` na gałąź `main`. Healthcheck: `/healthz`. Ustaw `SITE_URL` w zmiennych środowiskowych Railway po podpięciu domeny.

## Struktura

```
server.js                 serwer Express
railway.json              konfiguracja Railway
tools/build-og.js         rasteryzacja og-image
tools/screenshot.js       audyt wizualny
public/                   strona
  index.html
  polityka-prywatnosci.html
  regulamin.html
  404.html
  styles.css
  script.js
  img/                    zdjęcia WebP
```
