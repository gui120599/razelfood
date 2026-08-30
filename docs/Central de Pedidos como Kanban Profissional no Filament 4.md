# REFATORAÇÃO COMPLETA — CENTRAL DE PEDIDOS EM KANBAN

A implementação atual da **Central de Pedidos está ERRADA**.

Analise a tela atual antes de fazer qualquer alteração.

O resultado atual está apresentando os pedidos em uma única coluna vertical, parecendo uma lista/tabela, e isso **NÃO atende ao objetivo**.

Eu quero uma **CENTRAL DE PEDIDOS NO ESTILO KANBAN**, semelhante visualmente a sistemas como Trello, Jira ou painéis KDS de restaurantes.

## ⚠️ REGRA PRINCIPAL

### NÃO quero uma lista de pedidos.

### NÃO quero uma tabela.

### NÃO quero os status empilhados verticalmente.

### NÃO quero uma única coluna contendo todos os cards.

### QUERO COLUNAS KANBAN HORIZONTAIS.

A tela deve obrigatoriamente apresentar várias colunas lado a lado:

```text
┌────────────────┬────────────────┬────────────────┬────────────────┬────────────────┬────────────────┐
│ 🟡 NOVOS       │ 🔵 ACEITOS    │ 🟠 PREPARANDO  │ 🟢 PRONTOS     │ 🚚 ENTREGA     │ ✅ FINALIZADOS │
│                │                │                │                │                │                │
│ ┌────────────┐ │ ┌────────────┐ │ ┌────────────┐ │ ┌────────────┐ │ ┌────────────┐ │ ┌────────────┐ │
│ │ Pedido #10 │ │ │ Pedido #08 │ │ │ Pedido #07 │ │ │ Pedido #05 │ │ │ Pedido #04 │ │ │ Pedido #01 │ │
│ │ Cliente    │ │ │ Cliente    │ │ │ Cliente    │ │ │ Cliente    │ │ │ Cliente    │ │ │ Cliente    │ │
│ │ R$ 90,00   │ │ │ R$ 75,00   │ │ │ R$ 80,00   │ │ │ R$ 95,00   │ │ │ R$ 60,00   │ │ │ R$ 70,00   │ │
│ │ ⏱ 05 min   │ │ │ ⏱ 08 min   │ │ │ ⏱ 15 min   │ │ │ ⏱ 03 min   │ │ │ ⏱ 12 min   │ │ │ ✓ Entregue  │ │
│ │ [ACEITAR]  │ │ │ [INICIAR]  │ │ │ [PRONTO]   │ │ │ [ENVIAR]   │ │ │ [ENTREGUE] │ │ │            │ │
│ └────────────┘ │ └────────────┘ │ └────────────┘ │ └────────────┘ │ └────────────┘ │ └────────────┘ │
│                │                │                │                │                │                │
│ ┌────────────┐ │ ┌────────────┐ │                │                │                │                │
│ │ Pedido #11 │ │ │ Pedido #09 │ │                │                │                │                │
│ └────────────┘ │ └────────────┘ │                │                │                │                │
└────────────────┴────────────────┴────────────────┴────────────────┴────────────────┴────────────────┘
```

---

# 1. PRIMEIRA COISA: IGNORE A IMPLEMENTAÇÃO VISUAL ATUAL

Não tente corrigir a implementação atual adicionando mais CSS.

Faça uma análise da implementação existente e **REFATORE A VIEW para uma estrutura real de Kanban**.

Se a implementação atual estiver baseada em:

- Table
- Filament Table
- ListRecords
- Repeater
- Grid vertical
- Cards empilhados
- Sections verticais

não reutilize essa estrutura como layout principal.

A estrutura visual precisa ser:

```text
KANBAN BOARD
    ├── COLUMN
    │     ├── HEADER
    │     ├── CARD
    │     ├── CARD
    │     └── CARD
    │
    ├── COLUMN
    │     ├── HEADER
    │     ├── CARD
    │     └── CARD
    │
    └── COLUMN
          ├── HEADER
          └── CARD
```

---

# 2. LAYOUT DA PÁGINA

A Central de Pedidos deve ocupar **praticamente toda a largura disponível da área principal do Filament**.

Não deixe o Kanban preso em uma pequena coluna central.

A página deve ser:

```text
┌──────────────────────────────────────────────────────────────────────────────────────┐
│ CENTRAL DE PEDIDOS                                                🔄 Atualizar       │
│ Acompanhe os pedidos em tempo real                                                  │
├──────────────────────────────────────────────────────────────────────────────────────┤
│ [🔎 Buscar pedido...] [Todos ▾] [Todos entregadores ▾] [Data ▾] [⚠ Atrasados]       │
├──────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                      │
│                         KANBAN                                                       │
│                                                                                      │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐      │
│  │ NOVOS    │ │ ACEITOS  │ │PREPARANDO│ │ PRONTOS  │ │ ENTREGA  │ │FINALIZADO│      │
│  │    5     │ │    3     │ │    4     │ │    2     │ │    3     │ │   18     │      │
│  │          │ │          │ │          │ │          │ │          │ │          │      │
│  │ CARD     │ │ CARD     │ │ CARD     │ │ CARD     │ │ CARD     │ │ CARD     │      │
│  │ CARD     │ │ CARD     │ │ CARD     │ │          │ │ CARD     │ │ CARD     │      │
│  │ CARD     │ │ CARD     │ │ CARD     │ │          │ │          │ │ CARD     │      │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘      │
│                                                                                      │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

---

# 3. SCROLL HORIZONTAL

Essa regra é OBRIGATÓRIA.

As colunas precisam ficar lado a lado.

Se não houver espaço suficiente na tela:

**deve existir scroll horizontal no Kanban.**

Nunca faça as colunas quebrarem para uma nova linha.

Use algo conceitualmente equivalente a:

```css
display: flex;
flex-direction: row;
overflow-x: auto;
```

Cada coluna deve possuir uma largura mínima fixa.

Por exemplo:

```text
min-width: 300px
max-width: 380px
```

A largura pode ser ajustada conforme a melhor experiência visual.

Em um monitor grande, várias colunas devem aparecer simultaneamente.

Em telas menores, o usuário deve conseguir deslizar horizontalmente.

---

# 4. COLUNAS

Criar as seguintes colunas:

### 🟡 NOVOS

Status:

`INICIADO`

Descrição:

Pedidos aguardando aceite.

---

### 🔵 ACEITOS

Status:

`ABERTO`

Descrição:

Pedidos aceitos aguardando início do preparo.

---

### 🟠 PREPARANDO

Status:

`PREPARANDO`

Descrição:

Pedidos atualmente em produção.

---

### 🟢 PRONTOS

Status:

`PRONTO`

Descrição:

Pedidos prontos aguardando retirada ou entrega.

---

### 🚚 EM ENTREGA

Status:

`EM TRANSPORTE`

Descrição:

Pedidos que já saíram para entrega.

---

### ✅ FINALIZADOS

Status:

`ENTREGUE`
`FINALIZADO`

Descrição:

Pedidos concluídos.

---

### CANCELADOS

Não precisa ficar sempre visível como uma coluna principal.

Pode ser acessado através de:

`Ver cancelados`

ou filtro específico.

---

# 5. HEADER DE CADA COLUNA

Cada coluna deve possuir um cabeçalho visualmente destacado.

Exemplo:

```text
┌───────────────────────────────┐
│ 🟡  NOVOS                     │
│     5 pedidos                 │
└───────────────────────────────┘
```

O contador precisa ser dinâmico.

Exemplo:

`NOVOS · 5`

`PREPARANDO · 8`

`PRONTOS · 3`

Não colocar apenas o nome do status.

---

# 6. CARDS

Cada pedido deve ser um **CARD VISUAL**, claramente separado dos outros.

Não pode parecer uma linha de tabela.

Exemplo:

```text
┌───────────────────────────────┐
│ #1523                    12:42│
│                               │
│ João da Silva                 │
│ 📍 Delivery                   │
│                               │
│ 1x Pizza Calabresa            │
│ 1x Coca-Cola 2L               │
│                               │
│ ───────────────────────────── │
│                               │
│ 💰 R$ 78,00                   │
│ ⏱ 18 min                      │
│                               │
│ ⚠ ATRASADO                    │
│                               │
│ [ ACEITAR ]       [•••]       │
└───────────────────────────────┘
```

---

# 7. HIERARQUIA VISUAL DO CARD

A ordem das informações deve ser:

### Linha 1

Número do pedido:

**#1523**

Horário:

**12:42**

---

### Linha 2

Nome do cliente:

**João da Silva**

---

### Linha 3

Tipo:

`DELIVERY`

`RETIRADA`

`CONSUMO LOCAL`

---

### Linha 4

Resumo dos itens:

```text
1x Pizza Calabresa
1x Coca-Cola 2L
```

Mostrar somente os principais itens no card.

Se houver muitos itens:

`+ 4 itens`

---

### Linha 5

Valor:

**R$ 78,00**

---

### Linha 6

Tempo:

**⏱ 18 min**

---

### Linha 7

Ação principal.

Exemplo:

`ACEITAR`

`INICIAR PREPARO`

`MARCAR PRONTO`

`ENVIAR PARA ENTREGA`

`MARCAR ENTREGUE`

---

# 8. CARD ATRASADO

Pedidos atrasados precisam ser imediatamente identificáveis.

Não faça simplesmente:

`R$ 91,90 12h 48min · ATRASADO`

como está atualmente.

Isso é visualmente ruim.

Crie uma indicação visual própria no card.

Exemplo:

```text
┌───────────────────────────────┐
│ #1523                    12:42│
│ João da Silva                 │
│ DELIVERY                      │
│                               │
│ 1x Pizza Calabresa            │
│                               │
│ 💰 R$ 78,00                   │
│                               │
│ 🔴 ATRASADO · 42 min          │
│                               │
│ [ INICIAR PREPARO ]           │
└───────────────────────────────┘
```

O card pode ter uma borda ou indicador lateral para chamar atenção.

Não transforme toda a interface em vermelho.

---

# 9. CORES

Utilize a linguagem visual do próprio Filament.

Cada coluna pode possuir uma identificação visual discreta.

Exemplo conceitual:

- Novos → warning
- Aceitos → info
- Preparando → primary/orange
- Prontos → success
- Em entrega → azul
- Finalizados → verde

Mas:

**NÃO utilize cores saturadas em toda a coluna.**

A cor deve ser utilizada principalmente:

- no ícone
- no indicador
- no contador
- na borda
- no status

O fundo deve permanecer limpo.

---

# 10. AÇÕES

O botão principal do card deve depender do status.

### NOVOS

```text
[ ACEITAR ]
```

Menu secundário:

```text
Detalhes
Rejeitar
```

---

### ACEITOS

```text
[ INICIAR PREPARO ]
```

---

### PREPARANDO

```text
[ MARCAR COMO PRONTO ]
```

---

### PRONTOS — DELIVERY

```text
[ ENVIAR PARA ENTREGA ]
```

---

### PRONTOS — RETIRADA

```text
[ FINALIZAR ]
```

---

### EM ENTREGA

```text
[ MARCAR COMO ENTREGUE ]
```

---

### FINALIZADOS

Nenhuma ação operacional principal.

---

# 11. NÃO COLOQUE TODOS OS BOTÕES NO CARD

Não quero cards cheios de botões.

O card deve possuir:

**uma ação principal**

e um menu `•••` para ações secundárias.

Exemplo:

```text
[ ACEITAR ] [•••]
```

Menu:

```text
Ver detalhes
Ver histórico
Cancelar pedido
```

Isso deixa o Kanban muito mais limpo.

---

# 12. CLIQUE NO CARD

Ao clicar no card, abrir uma modal/drawer com os detalhes completos do pedido.

A modal deve mostrar:

## Pedido

- Número
- Status
- Data/hora

## Cliente

- Nome
- Telefone

## Entrega

- Tipo
- Endereço
- Bairro
- Complemento
- Referência
- Entregador

## Itens

Tabela/listagem visual dos produtos.

## Pagamento

- Total
- Forma de pagamento
- Status do pagamento

## Histórico

Timeline das mudanças de status.

---

# 13. TIMELINE

No detalhe do pedido:

```text
● Pedido criado
│ 12:30
│
● Pedido aceito
│ 12:32
│
● Preparação iniciada
│ 12:34
│
● Pedido pronto
│ 12:52
│
● Saiu para entrega
│ 12:58
│
● Entregue
  13:15
```

Mostrar usuário responsável quando disponível.

---

# 14. BARRA SUPERIOR

Acima do Kanban deve existir uma barra de controle limpa.

Exemplo:

```text
CENTRAL DE PEDIDOS

Acompanhe os pedidos em tempo real.

[ 🔎 Buscar pedido... ]

[ Todos ▾ ]
[ Entregadores ▾ ]
[ Data ▾ ]
[ ⚠ Atrasados ]

                    🔄 Atualizado há 5s
```

Os filtros NÃO podem ocupar uma enorme área vertical como na implementação atual.

A barra precisa ser compacta.

---

# 15. IMPORTANTE SOBRE A TELA ATUAL

Na imagem atual existem vários elementos empilhados:

```text
Buscar...
Todos entregadores
Data
até
Ver cancelados
Todos
Delivery
Retirada
...
Novos
#6
#7
#8
#9
...
```

Isso está errado.

Quero transformar essa estrutura em:

```text
┌─────────────────────────────────────────────────────────────┐
│ CENTRAL DE PEDIDOS                                          │
│ [Buscar] [Filtros]                         [Atualização]    │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ NOVOS      ACEITOS      PREPARANDO      PRONTOS      ...   │
│ ┌──────┐   ┌──────┐     ┌──────┐        ┌──────┐           │
│ │ #10  │   │ #08  │     │ #07  │        │ #05  │           │
│ └──────┘   └──────┘     └──────┘        └──────┘           │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

# 16. ALTURA DA TELA

O Kanban deve utilizar a altura disponível da tela.

Não quero uma página gigantesca onde os pedidos simplesmente ficam descendo verticalmente.

Cada coluna deve possuir seu próprio scroll vertical.

Conceitualmente:

```text
┌───────────────┐
│ NOVOS         │
├───────────────┤
│ CARD          │
│               │
│ CARD          │
│               │
│ CARD          │
│               │
│ CARD          │
│       ↕       │
│ scroll        │
└───────────────┘
```

Assim, o cabeçalho da coluna permanece visível enquanto os pedidos são rolados.

---

# 17. KANBAN NÃO É TABELA

Esse requisito é fundamental.

A estrutura final precisa ser semanticamente e visualmente um board:

```text
Board
 ├── Column
 │    ├── Card
 │    ├── Card
 │    └── Card
 ├── Column
 │    ├── Card
 │    └── Card
 └── Column
      ├── Card
      └── Card
```

Não:

```text
List
 ├── Pedido
 ├── Pedido
 ├── Pedido
 ├── Pedido
 └── Pedido
```

---

# 18. DRAG AND DROP

NÃO implemente drag-and-drop inicialmente.

O fluxo de status deve continuar sendo controlado pelas Actions.

Primeiro faça o Kanban funcionar perfeitamente.

Se futuramente for necessário drag-and-drop, ele deverá respeitar as mesmas regras de autorização e transição.

---

# 19. RESPONSIVIDADE

Desktop:

```text
[ NOVOS ][ ACEITOS ][ PREPARANDO ][ PRONTOS ][ ENTREGA ][ FINALIZADOS ]
```

Tablet:

```text
[ NOVOS ][ ACEITOS ][ PREPARANDO ]
        ← scroll →
```

Celular:

```text
[ NOVOS ]
   ↓
scroll horizontal
```

As colunas **nunca devem quebrar para outra linha**.

---

# 20. PERFORMANCE

Não carregar todos os pedidos históricos.

A Central deve priorizar pedidos ativos.

Pedidos:

`INICIADO`
`ABERTO`
`PREPARANDO`
`PRONTO`
`EM TRANSPORTE`

devem ter prioridade.

Finalizados podem possuir limite/período.

Evitar N+1.

Utilizar eager loading.

Não fazer polling excessivo.

Se já existir infraestrutura de WebSockets/Broadcasting, analisar e reutilizar.

---

# 21. REGRAS DE NEGÓCIO

Preservar o fluxo:

```text
INICIADO
   ↓
ABERTO
   ↓
PREPARANDO
   ↓
PRONTO
   ↓
EM TRANSPORTE
   ↓
ENTREGUE
   ↓
FINALIZADO
```

Para retirada/consumo local:

```text
INICIADO
   ↓
ABERTO
   ↓
PREPARANDO
   ↓
PRONTO
   ↓
FINALIZADO
```

Cancelamento:

```text
QUALQUER STATUS PERMITIDO
        ↓
    CANCELADO
```

Respeitando as regras/policies existentes.

---

# 22. MULTI-TENANCY

É obrigatório respeitar o tenant atual.

Nunca permitir que:

- pedido de outro tenant apareça
- pedido de outro tenant seja alterado
- entregador de outro tenant seja associado
- histórico de outro tenant seja acessado

As validações precisam existir no backend.

---

# 23. PERMISSÕES

Preservar RN-32.

Atendente/Gerente/Admin:

- Aceitar
- Rejeitar
- Avançar pedido
- Cancelar

Entregador:

- Marcar como entregue

Administrador/Gerente podem possuir permissões adicionais conforme a arquitetura já existente.

Não confiar apenas em esconder botões.

A Action também deve verificar autorização.

---

# 24. ARQUIVOS E ARQUITETURA

Antes de codificar:

1. Localize a implementação atual da Central.
2. Localize Model de Pedido/Venda.
3. Localize Enum de Status.
4. Localize Policies.
5. Localize estrutura de Tenant.
6. Localize relacionamento com Cliente.
7. Localize relacionamento com Itens.
8. Localize Entregadores.
9. Localize histórico de status.
10. Identifique o que já existe e reutilize.

Não duplicar Models, Enums ou tabelas.

---

# 25. RESULTADO ESPERADO

Ao final, quando eu abrir:

`/painel/cozinha`

quero enxergar imediatamente algo parecido com:

```text
┌───────────────────────────────────────────────────────────────────────────────────────────────┐
│ CENTRAL DE PEDIDOS                                                   🔄 Atualizado agora       │
│ Acompanhe os pedidos da operação                                                             │
│                                                                                               │
│ 🔎 Buscar pedido...     [Todos ▾] [Entregador ▾] [Data ▾] [⚠ Atrasados]                     │
├───────────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                               │
│ 🟡 NOVOS       🔵 ACEITOS       🟠 PREPARANDO       🟢 PRONTOS       🚚 ENTREGA       ✅ FIM │
│ 5 pedidos      3 pedidos        4 pedidos           2 pedidos        3 pedidos       8      │
│                                                                                               │
│ ┌───────────┐  ┌───────────┐   ┌───────────┐       ┌───────────┐   ┌───────────┐            │
│ │ #1523     │  │ #1520     │   │ #1518     │       │ #1515     │   │ #1512     │            │
│ │ João      │  │ Maria     │   │ Pedro     │       │ Carlos    │   │ Ana       │            │
│ │ DELIVERY  │  │ RETIRADA  │   │ DELIVERY  │       │ DELIVERY  │   │ DELIVERY  │            │
│ │           │  │           │   │           │       │           │   │           │            │
│ │ Pizza     │  │ 2 pizzas  │   │ Pizza +   │       │ Pizza     │   │ Pizza     │            │
│ │ + Refri   │  │           │   │ refri     │       │           │   │           │            │
│ │           │  │           │   │           │       │           │   │           │            │
│ │ R$ 78,00  │  │ R$ 92,00  │   │ R$ 65,00  │       │ R$ 81,00  │   │ R$ 70,00  │            │
│ │ ⏱ 05 min  │  │ ⏱ 08 min  │   │ 🔴 18 min │       │ ⏱ 03 min  │   │ ⏱ 12 min  │            │
│ │           │  │           │   │ ATRASADO  │       │           │   │           │            │
│ │ [ACEITAR] │  │ [INICIAR] │   │ [PRONTO]  │       │ [ENVIAR]  │   │[ENTREGUE] │            │
│ └───────────┘  └───────────┘   └───────────┘       └───────────┘   └───────────┘            │
│                                                                                               │
│              ← scroll horizontal caso necessário →                                           │
└───────────────────────────────────────────────────────────────────────────────────────────────┘
```

**Esse é o nível de resultado visual que espero.**

Não entregue novamente uma lista vertical com cards.

---

# 26. CRITÉRIO VISUAL DE APROVAÇÃO

Antes de considerar concluído, abra a página no navegador e faça uma análise visual.

Verifique:

- As colunas estão realmente lado a lado?
- Existe scroll horizontal?
- Os cards estão dentro das respectivas colunas?
- Cada coluna possui seu próprio scroll vertical?
- O Kanban ocupa a largura disponível?
- Os filtros estão compactos?
- Os pedidos não estão aparecendo como uma lista vertical?
- O card parece um card de pedido de restaurante?
- É possível identificar rapidamente o status?
- A ação principal está clara?
- A interface funciona em monitor grande?
- Não existe conteúdo quebrado ou desalinhado?

### SE A RESPOSTA PARA "AS COLUNAS ESTÃO LADO A LADO?" FOR NÃO:

**A IMPLEMENTAÇÃO ESTÁ ERRADA.**

Corrija antes de finalizar.

Não considere a tarefa concluída apenas porque o código compila.

O critério principal é:

> **A tela precisa parecer e funcionar como uma CENTRAL KANBAN DE PEDIDOS DE UMA PIZZARIA, e não como uma listagem administrativa do Filament.**