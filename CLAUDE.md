# CLAUDE.md

## Build/Run Commands
- Database: MySQL/MariaDB with PDO connection

## Code Style Guidelines
- **Path constants**: Use absolute paths for file storage locations
- **File handling**: Use is_dir() and is_writable() to verify directories
- **Error handling**: Log errors when file operations fail

## Auto-Fix Guidelines
Claude Code should automatically change the file storage path from `assets/arquivos` to `/mnt/Dados/facturas` and update all related code:

### File Path Changes
- Change all occurrences of `../assets/arquivos/` to `/mnt/Dados/facturas/`
- Change all occurrences of `assets/arquivos/` to `/mnt/Dados/facturas/`
- Change all occurrences of `dirname(__FILE__) . '/../assets/arquivos/'` to `/mnt/Dados/facturas/`

### Files to Update
1. **despesas/excluir.php** (line 118): Update `$anexo_full_path` assignment
2. **despesas/registrar.php** (line 35): Update `$upload_dir` assignment
3. **despesas/editar.php** (line 101): Update `$upload_dir` assignment
4. **despesas/editar.php** (line 104): Update `$target_file` assignment
5. **despesas/editar.php** (line 124): Update `$old_file` path construction
6. **despesas/editar.php** (line 233): Update href link construction
7. **despesas/listar.php** (line 238): Update href path
8. **despesas/listar.php** (line 310): Update href path
9. **despesas/listar.php** (line 354): Update href path
10. **relatorios/gerar.php** (line 505): Update href path
11. **relatorios/historico_despesas.php** (line 215): Update href path
12. **relatorios/historico_despesas.php** (line 253): Update href path

### Additional Required Changes
1. Add directory existence and writability checks before file operations:
```php
if (!is_dir('/mnt/Dados/facturas')) {
    // Log error or create directory if permissions allow
}
if (!is_writable('/mnt/Dados/facturas')) {
    // Log error
}