# RazelFood — Identidade Visual e Design System

**Documento de instrução para implementação (Claude Code / time de dev)**
**Versão:** 1.0
**Data:** 21/08/2026
**Depende de:** `requisitos-regras-negocio.md` (v3.0) e `modelagem-dados-middleware-multitenant.md` (v1.0)
**Fonte da paleta:** logos oficiais do RazelFood (`LOGO_RAZEL_FOOD.png`, lockup horizontal, 3766×1654px; `RAZELFOOD.png`, emblema/ícone, 1080×1080px), com cores extraídas por amostragem de pixel (não estimadas a olho) — ver seção 2.1.

---

## 0. Como usar este documento

Este documento define a identidade visual **própria do RazelFood** (não mais a identidade genérica da RazelTec) e traduz isso em tokens de cor, tipografia e instruções concretas de configuração do Filament 4 e do cardápio público (Blade + Livewire + Tailwind v4). É deliberadamente concreto — hex exato, nome de token, trecho de código — para ser colado direto num prompt de implementação.

**Guardrails (não fazer, mesmo que pareça natural durante a implementação):**
- Não aplicar a paleta laranja/navy do RazelFood por cima do cardápio público de um tenant — a marca do **produto** vive no painel/chrome administrativo; a vitrine do cliente final segue a identidade do **restaurante** (RF-16, `tenants.primary_color`, `tenants.logo_path`). Ver seção 1.
- Não hardcodar hex direto em componentes Blade/Livewire/Filament — sempre via tokens definidos na seção 2.
- Não usar o tom "dourado/brass" (estimado visualmente, não amostrado — ver nota na seção 2.1) em texto de corpo ou em botões cujo contraste é crítico; reservar para detalhes decorativos.
- Não misturar os semânticos de sucesso (verde) e cancelamento (vermelho) com o laranja de marca no mesmo contexto — laranja significa "marca/ação", não "atenção" ou "erro".

---

## 1. Duas marcas convivendo no mesmo SaaS

O RazelFood é multi-tenant e tem, por natureza, **dois contextos visuais diferentes** que não podem se misturar:

| Contexto | Marca aplicada | Por quê |
|---|---|---|
| **Painel administrativo** (Tenant Panel e Central Panel, ambos Filament) | **RazelFood** — a paleta e o grafismo deste documento | É o produto da Razel Tec. Todo tenant usa o mesmo painel; a consistência visual entre eles reforça "isso é um produto RazelFood", igual ao RazelMed Insights reforça a marca RazelTec pros hospitais. |
| **Cardápio público** (`/`, `/acompanhar/{order}` — Blade + Livewire) | **Identidade do próprio restaurante** (tenant) | O cliente final está comprando pizza do *restaurante X*, não navegando "no RazelFood". Forçar laranja/navy do RazelFood em cima da marca do cliente seria como a maquininha de cartão exibir a logo da adquirente em vez do nome da loja. |

Isso **refina** (não contradiz) a RNF-06 do documento de regras de negócio: onde ela dizia "identidade RazelTec no painel administrativo", leia-se agora **identidade RazelFood** — o produto amadureceu a ponto de ter logo e paleta próprias, então o painel usa a marca do produto específico, não mais a marca-mãe genérica. A regra de fundo (chrome = nosso, vitrine = do cliente) continua exatamente a mesma.

Na prática:
- `TenantPanelProvider` e `CentralPanelProvider` (Filament) → tema RazelFood (seção 4).
- Layout do cardápio público (`resources/views/menu/layout.blade.php` ou equivalent) → tema **fallback neutro**, sobrescrito em runtime pelas colunas `tenants.primary_color` / `tenants.logo_path` de cada tenant (seção 5). O RazelFood só aparece ali como um rodapé discreto tipo "cardápio powered by RazelFood".

---

## 2. Paleta de cores

### 2.1 Extração (amostragem real de pixel, não estimativa)

As cores abaixo foram extraídas diretamente dos arquivos de logo via script Python (PIL, quantização de pixels não-transparentes e não-neutros), separando as três áreas visuais da logo: o emblema circular (fundo navy + chama laranja), a palavra "RAZEL" (degradê azul-petróleo) e a palavra "FOOD" (degradê âmbar-laranja). Os valores marcados como "estimado" são a única exceção — não foram amostrados com confiança porque se misturam com reflexos metálicos.

**Navy (fundo do emblema — família escura/base):**
`#001428` `#001E3C` `#002846` `#003250` `#003C64`

**Laranja/âmbar ("FOOD" + chama do forno — família de destaque/ação):** essa é literalmente uma rampa contínua na própria arte (canal R fixo em `FA`, canal G subindo de `5A` a `BE`):
`#FA5A00` → `#FA6400` → `#FA6E00` → `#FA7800` → `#FA8200` → `#FA8C00` → `#FA9600` → `#FAA000` → `#FAAA00` → `#FAB400` → `#FABE00`

**Azul-petróleo ("RAZEL" — família secundária/fria):**
`#003264` `#005078` `#007896` `#008CB4` `#0096B4`

**Dourado/brass (dentes da engrenagem — decorativo, ⚠️ estimado, não amostrado por pixel):**
`#C9A057` (usar só em detalhes/ícones, nunca como cor de texto ou de botão principal)

### 2.2 Tokens de marca

| Token | Hex | Uso |
|---|---|---|
| `rf-navy-950` | `#001428` | Fundo mais profundo (hero, login, dark mode base) |
| `rf-navy-900` | `#001E3C` | Fundo padrão do painel em dark mode |
| `rf-navy-800` | `#002846` | Surfaces / cards elevados |
| `rf-navy-700` | `#003250` | Bordas, hover sutil em dark mode |
| `rf-navy-600` | `#003C64` | Bordas mais claras, divisores |
| `rf-orange-700` | `#FA5A00` | Hover/active de botão primário, texto sobre fundo claro (mais escuro = melhor contraste) |
| `rf-orange-600` | `#FA6400` | **Cor primária de marca** — botões, links de ação, ícone ativo |
| `rf-orange-500` | `#FA7800` | Estado default alternativo / ícones |
| `rf-orange-400` | `#FA9600` | Accents secundários, hover claro |
| `rf-amber-300` | `#FAB400` | Badges de destaque ("promoção", "mais vendido"), realces sobre fundo navy |
| `rf-teal-700` | `#003264` | Texto/ícone secundário sobre fundo claro |
| `rf-teal-500` | `#007896` | Links secundários, estado "informativo" |
| `rf-teal-300` | `#0096B4` | Accent frio sobre fundo navy (contraste com o laranja) |
| `rf-brass` | `#C9A057` | Decorativo apenas (ícones, engrenagem, flourish) — ver guardrail |

### 2.3 Semânticos (propositalmente fora da paleta da logo)

Laranja já significa "marca/ação" — usá-lo também para "sucesso" ou "erro" cria ambiguidade. Por isso os semânticos de status usam cores padrão, não extraídas da logo:

| Token | Hex | Uso |
|---|---|---|
| `rf-success` | `#16A34A` | Confirmações, "entregue/finalizado" |
| `rf-danger` | `#DC2626` | Erros, "cancelado" |
| `rf-warning` | `#F59E0B` | Avisos (próximo do `rf-amber-300`, mas mantido separado para não acoplar semântica de alerta ao token de marca) |
| `rf-gray-*` | escala `slate` padrão do Tailwind | Textos neutros, backgrounds em light mode |

### 2.4 Mapeamento de status do pedido → cor (Kanban / Filament)

Aplicar essa paleta nos badges de status do `PedidoResource`/`OrderResource` (state machine da seção 3 do doc de regras de negócio):

| Status | Token | Justificativa |
|---|---|---|
| `started` (INICIADO) | `rf-gray-400` | Neutro — aguardando ação humana, ainda não é "trabalho confirmado" |
| `open` (ABERTO) | `rf-teal-500` | Ação humana ocorreu, frio = "recebido, sob controle" |
| `preparing` (PREPARANDO) | `rf-amber-300` | Quente, em progresso |
| `ready` (PRONTO) | `rf-orange-600` | Cor de marca no auge — pico de atenção |
| `in_transit` (EM TRANSPORTE) | `rf-teal-300` | Movimento, volta ao frio |
| `delivered` / `finished` | `rf-success` | Semântico de sucesso, não de marca |
| `cancelled` | `rf-danger` | Semântico de erro, não de marca |

---

## 3. Tipografia e grafismo

Mantém o que já está definido no guia da RazelTec — a logo do RazelFood não sugere mudança de fonte:

- **Títulos:** Space Grotesk
- **Corpo:** Inter
- **Rótulos técnicos / eyebrows / badges de status:** JetBrains Mono

**Motivo gráfico da marca** (para reaproveitar em favicon, empty states, spinners de loading, telas de onboarding — nunca no cardápio público de um tenant):
- Emblema circular com engrenagem + forno a lenha com chama — comunica "operação + comida artesanal + automação".
- Anel de ícones orbitando o centro (cifrão, termômetro, gráfico/caminhão, pessoas à mesa) — pode virar um motivo de "features em órbita" em telas de marketing/onboarding.
- Degradê frio→quente no wordmark (`RAZEL` em azul-petróleo, `FOOD` em âmbar-laranja) — reaproveitar como gradiente de texto em headings de destaque (seção 4.4).

---

## 4. Configuração no Filament (Tenant Panel + Central Panel)

### 4.1 Tokens no Tailwind v4 (CSS-first, sem `tailwind.config.js`)

```css
/* resources/css/filament/theme.css (ou equivalente por painel) */
@import 'tailwindcss';
@import '../../../vendor/filament/filament/resources/css/theme.css';

@theme {
  --color-rf-navy-950: #001428;
  --color-rf-navy-900: #001E3C;
  --color-rf-navy-800: #002846;
  --color-rf-navy-700: #003250;
  --color-rf-navy-600: #003C64;

  --color-rf-orange-700: #FA5A00;
  --color-rf-orange-600: #FA6400;
  --color-rf-orange-500: #FA7800;
  --color-rf-orange-400: #FA9600;

  --color-rf-amber-300: #FAB400;

  --color-rf-teal-700: #003264;
  --color-rf-teal-500: #007896;
  --color-rf-teal-300: #0096B4;

  --color-rf-brass: #C9A057;

  --font-heading: 'Space Grotesk', sans-serif;
  --font-body: 'Inter', sans-serif;
  --font-mono: 'JetBrains Mono', monospace;
}
```

### 4.2 `->colors()` no PanelProvider

Filament gera a escala completa (50–950) a partir de um único hex — não escrever a rampa manualmente:

```php
// app/Providers/Filament/TenantPanelProvider.php
// app/Providers/Filament/CentralPanelProvider.php (mesma paleta nos dois — é a marca do produto)
use Filament\Support\Colors\Color;

$panel
    ->colors([
        'primary' => Color::hex('#FA6400'),   // rf-orange-600
        'info'    => Color::hex('#007896'),   // rf-teal-500
        'success' => Color::hex('#16A34A'),   // rf-success
        'warning' => Color::hex('#F59E0B'),   // rf-warning
        'danger'  => Color::hex('#DC2626'),   // rf-danger
        'gray'    => Color::hex('#001E3C'),   // usa o navy como base da escala neutra
    ])
    ->darkMode(true) // dark mode é o padrão da identidade
    ->font('Inter')
    ->viteTheme('resources/css/filament/theme.css');
```

> Verificar a assinatura exata de `->darkMode()` / `->defaultThemeMode()` contra a versão do Filament instalada (a API teve pequenas variações entre v3 e v4) antes de assumir o snippet acima ao pé da letra.

### 4.3 Branding do painel (logo, favicon, nome)

```php
$panel
    ->brandName('RazelFood')
    ->brandLogo(asset('images/brand/razelfood-lockup.svg'))       // wordmark completo (login, topo do sidebar expandido)
    ->brandLogoHeight('2rem')
    ->favicon(asset('images/brand/razelfood-icon.png'))            // RAZELFOOD.png (1080×1080, já quadrado)
    ->darkModeBrandLogo(asset('images/brand/razelfood-lockup-dark.svg'));
```

**Assets a gerar a partir dos arquivos enviados** (`LOGO_RAZEL_FOOD.png` e `RAZELFOOD.png`):

| Arquivo de origem | Uso | Onde salvar |
|---|---|---|
| `RAZELFOOD.png` (ícone, 1080×1080) | Favicon, ícone de app/PWA, avatar em notificações | `public/images/brand/razelfood-icon-{16,32,180,512}.png` |
| `LOGO_RAZEL_FOOD.png` (lockup horizontal) | Login do painel, cabeçalho de e-mail transacional, materiais de marketing | `public/images/brand/razelfood-lockup.png` (+ versão SVG se houver arte vetorial original) |

### 4.4 Tela de login com o gradiente da marca

```css
/* resources/css/filament/theme.css — complemento */
.fi-simple-layout {
  background:
    radial-gradient(circle at 15% 15%, color-mix(in srgb, var(--color-rf-orange-600) 18%, transparent), transparent 40%),
    linear-gradient(160deg, var(--color-rf-navy-950) 0%, var(--color-rf-navy-900) 60%, var(--color-rf-navy-800) 100%);
}

.rf-gradient-text {
  background: linear-gradient(90deg, var(--color-rf-teal-500), var(--color-rf-amber-300), var(--color-rf-orange-600));
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}
```

Reproduz o efeito "neon/glow sobre fundo escuro" já esperado pela identidade da RazelTec, agora com o degradê real do wordmark (frio → quente) em vez do azul/violeta genérico.

---

## 5. Cardápio público (fallback neutro + branding do tenant)

O cardápio **não** usa `rf-orange-*`/`rf-navy-*` como padrão fixo. Ele usa uma paleta neutra de fallback, sobrescrita em runtime pela cor que o próprio restaurante configurou (`tenants.primary_color`, já modelada no schema — RF-16).

### 5.1 Gerando uma escala a partir de uma única cor do tenant

Como `tenants.primary_color` guarda só um hex, um helper gera tons claros/escuros na hora de montar o layout (evita pedir ao lojista para escolher 10 tons):

```php
// app/Support/TenantColorScale.php
namespace App\Support;

class TenantColorScale
{
    /** Gera variações de luminosidade em HSL a partir de um único hex. */
    public static function cssVariables(string $hex): string
    {
        [$h, $s, $l] = self::hexToHsl($hex);

        $stops = [
            '50' => min($l + 45, 96), '100' => min($l + 35, 92), '200' => min($l + 25, 88),
            '300' => min($l + 15, 82), '400' => min($l + 7, 75), '500' => $l,
            '600' => max($l - 8, 15), '700' => max($l - 16, 10), '800' => max($l - 24, 7), '900' => max($l - 32, 4),
        ];

        $css = ':root{';
        foreach ($stops as $step => $lightness) {
            $css .= "--tenant-{$step}: hsl({$h} {$s}% {$lightness}%);";
        }
        return $css . '}';
    }

    private static function hexToHsl(string $hex): array
    {
        // implementação padrão hex→HSL; manter aqui só a assinatura de referência
    }
}
```

```blade
{{-- resources/views/menu/layout.blade.php --}}
<style>{!! \App\Support\TenantColorScale::cssVariables($tenant->primary_color ?? '#FA6400') !!}</style>
```

`#FA6400` (`rf-orange-600`) só entra como **valor default** quando o tenant ainda não configurou `primary_color` no onboarding — nesse caso o cardápio nasce parecido com a marca RazelFood até o lojista personalizar, o que é aceitável como estado transitório, não como identidade fixa.

### 5.2 Rodapé de atribuição (o único lugar do cardápio onde o RazelFood aparece)

```blade
<footer class="text-center text-xs text-slate-400 py-4">
  Cardápio via <span class="font-heading text-rf-orange-600">RazelFood</span>
</footer>
```

Discreto, sem gradiente, sem competir com a marca do restaurante.

---

## 6. Checklist de implementação (Claude Code)

- [ ] Criar `resources/css/filament/tenant/theme.css` e `resources/css/filament/central/theme.css` com os tokens da seção 4.1.
- [ ] Registrar `->viteTheme()` nos dois `PanelProvider`s.
- [ ] Aplicar `->colors()` com `Color::hex()` (seção 4.2) nos dois painéis — mesma paleta, é a marca do produto.
- [ ] Gerar os assets de favicon/ícone a partir de `RAZELFOOD.png` (16/32/180/512px) e salvar em `public/images/brand/`.
- [ ] Configurar `->brandLogo()` / `->favicon()` / `->brandName('RazelFood')` nos dois painéis.
- [ ] Aplicar o mapeamento de cor por status (seção 2.4) nos badges do `OrderResource`/kanban.
- [ ] Implementar `TenantColorScale` (ou equivalente) e injetar `--tenant-*` no layout do cardápio público, com fallback em `rf-orange-600`.
- [ ] **Não** aplicar `rf-navy-*`/`rf-orange-*` fixos no cardápio público além do fallback — sempre condicionado a "tenant sem `primary_color` definido".
- [ ] Validar contraste (WCAG AA) do laranja de marca sobre fundo claro — preferir `rf-orange-700` (`#FA5A00`) para texto, reservar `rf-orange-600` (`#FA6400`) para backgrounds de botão com texto branco.

---

*Este documento deve ser lido em conjunto com `requisitos-regras-negocio.md` e `modelagem-dados-middleware-multitenant.md`. Refina a RNF-06 daquele documento (troca "identidade RazelTec" por "identidade RazelFood" no painel administrativo) — atualizar a RNF-06 na próxima revisão do documento de regras de negócio para manter as fontes consistentes entre si.*
