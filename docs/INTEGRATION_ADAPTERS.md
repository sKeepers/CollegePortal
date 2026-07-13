# Integration Adapters

Adapters implement a small interface: name, version, health check, capabilities, read-only execution, command execution and diagnostic redaction.

## FIS adapter

Enabled capabilities: `zkspd_check`, `test_service_check`, `dictionaries_list`, `dictionaries_details`, `institution_info`, `check_application`.

Disabled capabilities: `validate`, `import`, `import_result`, `production`.

Future adapters for FRDO, Moodle, LDAP/AD, MAX, Telegram, Email and access-control systems must reuse the common Gateway protocol and avoid adapter-specific security bypasses.
