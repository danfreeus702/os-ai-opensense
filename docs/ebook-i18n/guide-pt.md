# OPNsense AI Security Wizard: Guia Prático

**Rascunho v0.1 — Daniel Freeman (danfreeus702)**
*Companion do plugin de código aberto: https://github.com/danfreeus702/os-ai-opensense*

> Este é um rascunho de trabalho. Os capítulos 1–2 estão concluídos; os demais
> estão esboçados e serão preenchidos a partir dos relatórios de teste do projeto.

---

## Índice

1. Por que existe este assistente
2. Início rápido: instalar e aplicar pela primeira vez
3. Os três níveis, explicados
4. DNS criptografado no OPNsense 26.7 (por que DoT e não DoH)
5. O assistente de IA: documentação oficial e hardware
6. Ler as diferenças em linguagem simples
7. Reversão: snapshots e desfazer com um clique
8. Usar o assistente offline
9. Verificação: como o plugin foi testado
10. Solução de problemas e limitações reais
11. Apêndice A — ações do configd e caminhos de arquivos
12. Apêndice B — atualizar e desinstalar

---

## Capítulo 1 — Por que existe este assistente

Um firewall recém-instalado é uma tela em branco. Essa é a sua força e a sua
fraqueza. Ele pode fazer qualquer coisa, mas quem está diante da interface
precisa decidir *o quê*, *onde* e *em que ordem*. No OPNsense isso significa
navegar mais de 150 links de menu entre firewall, DNS, VPN, serviços e sistema.
A maioria das pessoas acaba deixando o equipamento pela metade ou copiando
configurações que não entende.

O assistente de segurança faz uma pergunta diferente: *quanto proteção você
quer?* Em seguida, produz um plano que você pode revisar. Você continua vendo
cada alteração antes que ela aconteça, incluindo o caminho exato do menu onde
poderia fazê-la manualmente. O assistente não é uma caixa-preta: é um atalho
com um registro do que faz.

### Regras de projeto

Quatro regras orientaram o projeto:

1. **Nada muda sem confirmação.** Toda ação que altera algo exige um
   `confirm=yes` explícito e a interface mostra primeiro as diferenças em
   linguagem simples.
2. **Nenhum segredo no config.xml.** A chave do modelo na nuvem fica em um
   arquivo restrito ao root, `/conf/aiopensense.key` (modo 600), para não ser
   copiada nos backups.
3. **Toda aplicação tem reversão.** Antes da primeira alteração, é gravado um
   snapshot do arquivo de configuração com carimbo de data/hora, e um único
   botão «Undo last change» o restaura.
4. **Apenas modelos na nuvem.** Nenhum modelo local é incluído, então o plugin
   funciona em equipamentos pequenos com pouca memória.

### Para quem é

- Usuários domésticos que querem uma base segura em poucos minutos.
- Pequenos escritórios sem engenheiro de rede dedicado.
- Quem está aprendendo OPNsense e quer ver *como é uma configuração correta*
  antes de fazê-la manualmente.

Se você administra um ambiente complexo com várias WAN, BGP e uma pilha IDS
completa, este assistente não substitui o seu projeto — é um ponto de partida
limpo.

---

## Capítulo 2 — Início rápido: instalar e aplicar pela primeira vez

### O que você precisa

- **OPNsense 26.7** ou superior (testado na versão 26.7.9_3).
- Acesso à internet a partir do firewall para baixar listas de bloqueio e
  consultar o modelo na nuvem.
- Uma chave de API do OpenRouter (principal) ou NVIDIA NIM (alternativa).

### Instalar o pacote

Baixe `os-ai-opensense-0.1_1.pkg` da página de versões do GitHub, copie-o para
o firewall e instale-o como root:

```sh
pkg add /tmp/os-ai-opensense-0.1_1.pkg
```

Os hooks de instalação reiniciam o configd e carregam o novo modelo de tela.
Confirme com:

```sh
pkg info os-ai-opensense
```

### Adicionar a chave de API (somente root)

```sh
printf '%s\n' 'your-key-here' > /conf/aiopensense.key
chmod 600 /conf/aiopensense.key
```

Nunca coloque a chave no `config.xml`: esse arquivo acaba em backups e em
pacotes de suporte.

### Abrir o assistente

Na interface web, escolha **OPNsense AI → Security Wizard**. Você verá três
cartões. Selecione um nível; o painel do plano abre com uma lista de etapas.
Abaixo dele está a tabela **What will change**, com quatro colunas:

- a alteração,
- o valor atual,
- o valor após a aplicação,
- o caminho exato da interface onde essa configuração fica.

Leia essa tabela. Se algo parecer errado, feche a página — nada foi alterado
ainda.

### Aplicar

Marque as opções (o nó de saída do Tailscale só existe no nível 3) e clique em
**Apply selected level**. A caixa de confirmação avisa que um snapshot de
reversão será salvo automaticamente. Após alguns segundos, você verá um visto
verde para cada etapa. O painel de resultado também mostra a nota sobre o
snapshot.

### Verificar

```sh
tail -20 /var/log/aiopensense-security.log
pfctl -sr | grep -E 'domain|https|domain-s'
```

As regras de DNS e HTTPS do assistente devem aparecer entre as regras ativas do
pf, e uma linha `OK` deve existir para cada ação no registro de auditoria.

### Desfazer

Se quiser voltar atrás, clique em **Undo last change** no painel de resultado.
O controlador restaura o último snapshot e recarrega as regras do firewall.
Confira o nível aplicado:

```sh
grep -o '<security_level>[0-9]*</security_level>' /conf/config.xml
```

---

*Capítulos 3–12: esboçados acima. Próximos a redigir: «Os três níveis,
explicados» e «DNS criptografado no OPNsense 26.7 (por que DoT e não DoH)», a
partir do NOTES.md e das constantes RESOLVERS/BLOCKLISTS do modelo.*
