# Oportuniza Bié

Plataforma Web académica para aproximar candidatos a emprego, empregadores, prestadores de serviços, clientes, vendedores e compradores no Município do Cuito, Província do Bié.

## Funcionalidades implementadas no MVP

- Registo e autenticação com palavras-passe protegidas por `password_hash`/`password_verify`, sessões PHP e token CSRF.
- Conta pessoal com edição de perfil profissional e activação de várias actividades (candidato, empregador, prestador, cliente, vendedor e comprador).
- Publicação e pesquisa de vagas; candidatura; cálculo meramente indicativo de compatibilidade a partir das competências e formação; actualização do estado das candidaturas pelo empregador.
- Publicação e consulta de microtarefas; manifestação de interesse; selecção de prestador, conclusão de tarefa e avaliação por participantes elegíveis.
- Publicação, pesquisa e contacto directo com prestadores de serviços.
- Lojas, produtos com fotografias opcionais validadas, stock, carrinho, pedido de contacto e notificações para os vendedores.
- Denúncias de anúncios e painel administrativo com indicadores, moderação e consulta de contas.
- Registos de auditoria para operações seleccionadas.
- Interface responsiva para computador e telemóvel.

## Limitações propositadas nesta versão

- O gateway de pagamentos não está ligado. Criar um pedido no mercado **não cobra dinheiro nem confirma pagamento**; o pedido fica pendente para contacto entre comprador e vendedores. Não introduzas credenciais bancárias nem dados de cartão nesta versão.
- A plataforma não verifica automaticamente a identidade de empresas, vendedores ou candidatos. A verificação administrativa ainda é básica.
- O cálculo de compatibilidade profissional é heurístico, informativo e não representa a probabilidade de contratação.
- O protótipo necessita de revisão de segurança, adequação legal, testes com utilizadores e configuração de produção antes de ser disponibilizado publicamente.
- O número de anúncios pode começar em zero numa base de dados nova; cria contas e publica dados de teste para validar os fluxos.

## Requisitos

- PHP 8.2 ou superior, com extensões `pdo` e `pdo_mysql`.
- MySQL 8.0 ou superior.
- Apache com `mod_rewrite` para aplicar as regras de protecção do `.htaccess` (o servidor PHP integrado não aplica esse ficheiro).
- Navegador moderno.

## Editar no VS Code Web

Podes abrir o projecto no [VS Code Web](https://vscode.dev/) e editar os ficheiros directamente no navegador. Contudo, o editor Web sozinho não executa PHP nem inicia um servidor MySQL. Para executar e testar a aplicação no navegador, utiliza o GitHub Codespaces, seguindo a opção A abaixo, ou um ambiente local como o XAMPP.

## Opção A — GitHub Codespaces com PHP e MySQL

O repositório contém `.devcontainer/` e um `Dockerfile` para preparar PHP/Apache e MySQL dentro do Codespaces.

1. Cria um repositório privado ou público no GitHub e carrega todos os ficheiros do projecto.
2. No repositório, escolhe **Code → Codespaces → Create codespace on main**.
3. O Codespace deve construir os serviços `app` e `db`. A porta 80 é encaminhada para o navegador.
4. Abre a aplicação pela porta 80. A base de dados é inicializada com `database/schema.sql` na primeira criação do volume MySQL.
5. Se alterares o esquema depois de a base de dados já ter sido inicializada, as alterações do ficheiro SQL não são aplicadas automaticamente. Em desenvolvimento, podes eliminar o volume MySQL e recriá-lo (isso apaga todos os dados existentes) ou aplicar uma migração SQL.

As credenciais presentes em `.devcontainer/docker-compose.yml` são apenas para desenvolvimento descartável. Não as reutilizes em produção.

## Opção B — XAMPP no computador

1. Instala e inicia Apache e MySQL no XAMPP.
2. Copia a pasta `oportuniza-bie` para `htdocs`.
3. Abre o phpMyAdmin, importa o ficheiro `database/schema.sql` e confirma que a base de dados `oportuniza_bie` foi criada.
4. Confirma que o utilizador do Apache/PHP tem permissão de escrita em `uploads/products` para guardar fotografias; não uses permissões abertas numa instalação pública.
5. Configura as variáveis de ambiente `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS`. Em XAMPP, podes inicialmente usar `127.0.0.1`, `3306`, `oportuniza_bie`, `root` e palavra-passe vazia apenas num ambiente local e descartável.
6. Abre `http://localhost/oportuniza-bie/`.

Para um ambiente que não carregue variáveis de ambiente para o Apache, configura-as no servidor/PHP; evita colocar credenciais reais no código fonte.

## Opção C — servidor PHP integrado

Serve para verificar páginas e interface. É necessário ter PHP instalado:

```bash
php -S 127.0.0.1:8000
```

Abre `http://127.0.0.1:8000`. O servidor integrado **não lê `.htaccess`** e não substitui o MySQL. Sem `pdo_mysql` e sem uma base de dados acessível, as páginas públicas carregam em modo de diagnóstico, mas as operações de registo, anúncio e encomenda não poderão ser usadas.

## Configuração de base de dados

O ficheiro `.env.example` documenta as variáveis esperadas, mas o PHP não carrega automaticamente ficheiros `.env`. Define as variáveis no ambiente de execução ou na configuração do servidor.

- `DB_HOST`: endereço do servidor MySQL.
- `DB_PORT`: porta MySQL (por defeito 3306).
- `DB_NAME`: por defeito `oportuniza_bie`.
- `DB_USER` / `DB_PASS`: conta própria da aplicação.
- `APP_TIMEZONE`: por defeito `Africa/Luanda`.

## Criar uma conta de administrador

1. Regista uma conta normal através da página **Criar conta**.
2. Executa na raiz do projecto, no terminal do ambiente com as variáveis de base de dados configuradas:

```bash
php scripts/promote-admin.php email@exemplo.com
```

3. Termina a sessão e entra novamente. O script só funciona no terminal (CLI), não através do navegador.

## Testes

Executa na raiz do projecto:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
bash tests/run-smoke.sh
```

Os testes de fumo verificam a sintaxe e a resposta HTTP das páginas. Para testar registo, login, candidaturas, publicação de anúncios, carrinho, encomendas e administração, é necessário ter MySQL activo e o esquema importado.

## Segurança antes de produção

Utiliza HTTPS, credenciais dedicadas e fortes, backups, limitação de tentativas de login, verificação de e-mail, política de privacidade, validação de ficheiros (quando forem permitidos anexos), moderação operacional e revisão independente de segurança. A integração de pagamentos só deve ser activada após contratação e confirmação das condições técnicas, comerciais e legais do prestador escolhido para Angola.
