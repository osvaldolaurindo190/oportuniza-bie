# Relatório de testes — Oportuniza Bié

## Testes automatizados executados no ambiente de desenvolvimento

- Sintaxe PHP verificada em todos os ficheiros `.php` com `php -l`.
- Testes unitários de escape HTML, validação de datas, formatação monetária, compatibilidade indicativa, protecção CSRF, controlo de funções, renovação do token após autenticação e sessão: executar com `php tests/unit.php`.
- Testes de fumo HTTP às páginas inicial, registo, entrada, emprego, microtarefas, serviços e mercado: executar com `bash tests/run-smoke.sh`.
- Validação do JSON de configuração do Dev Container, do YAML do Docker Compose e da sintaxe do script de entrada.

## Limitação do ambiente usado nesta revisão

O ambiente de revisão não dispõe do controlador PHP `pdo_mysql`, de um servidor MySQL nem do comando Docker. Por esse motivo, não foi possível executar aqui os fluxos de integração que gravam dados na base de dados. Os testes de fumo confirmam que as páginas respondem; não substituem os testes de registo, login, candidaturas, encomendas ou moderação com MySQL activo.

## Lista de verificação manual no Codespaces ou XAMPP

1. Confirmar que `pdo_mysql` aparece em `php -m` e que a base de dados foi inicializada com `database/schema.sql`.
2. Criar contas de teste para candidato, empregador, prestador de serviços, cliente e vendedor/comprador; testar entrada, saída e actualização do perfil.
3. Publicar uma vaga, candidatar-se com outra conta e actualizar o estado da candidatura como empregador.
4. Publicar uma microtarefa, responder como prestador, seleccionar um prestador, marcar a tarefa como concluída e testar avaliações de ambas as partes.
5. Publicar um serviço; confirmar que o telefone só fica público quando é preenchido expressamente no anúncio.
6. Publicar um produto com e sem imagem, testar formatos de imagem inválidos, adicionar/remover artigos do carrinho e actualizar quantidades.
7. Enviar um pedido de mercado; confirmar que surge na conta do vendedor e na conta do comprador, que a notificação é criada e que **não há cobrança nem pagamento electrónico real**.
8. Confirmar que não se consegue comprar produto da própria loja, candidatar-se duas vezes à mesma vaga/tarefa, avaliar uma tarefa não concluída ou executar uma acção administrativa sem permissão.
9. Denunciar um anúncio e verificar o tratamento da denúncia no painel de administração.

Usar apenas dados fictícios e contas de teste. Antes de disponibilização pública, efectuar revisão de segurança e confirmar as condições legais e comerciais dos serviços externos.
