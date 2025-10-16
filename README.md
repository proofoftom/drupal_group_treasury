# Group Treasury Module

Integrates Safe Smart Accounts as treasuries for Drupal Groups.

## Overview

This module allows Drupal Groups to have treasury Safe Smart Accounts, enabling:
- Multi-signature treasury management for groups
- Transaction proposals by group members
- Transaction signing and execution by group admins
- Automatic signer synchronization based on group roles
- Treasury deployment during group creation (optional per Group Type)

## Requirements

- Drupal 10.x
- Group module (drupal/group)
- Safe Smart Accounts module (safe_smart_accounts)
- PHP 8.1+ with GMP extension

## Installation

1. Enable the module:
   ```bash
   drush en group_treasury -y
   drush cr
   ```

2. Configure Group Type settings:
   - Go to `/admin/group/types`
   - Edit your Group Type (e.g., "DAO")
   - Enable "Group creator must complete treasury deployment" if desired
   - Save configuration

3. Add the group_safe_account relationship plugin to your Group Type:
   - In the Group Type configuration
   - Navigate to "Installed plugins" tab
   - Enable "Group treasury (Safe Smart Account)"
   - Configure cardinality (default: 1 treasury per group)

## Features

### Treasury Tab
- Accessible at `/group/{id}/treasury`
- Shows treasury Safe address, balance, and signers
- Lists pending and executed transactions
- Provides "Propose Transaction" action for members

### Permissions
- **View group treasury**: View the Treasury tab (default: members)
- **Propose treasury transactions**: Create transaction proposals (default: members)
- **Sign treasury transactions**: Add signatures (default: admins)
- **Execute treasury transactions**: Submit to blockchain (default: admins)
- **Manage group treasury**: Add/remove/reconnect treasury (default: admins)

### Transaction Workflow
1. Member proposes transaction via "Propose Transaction" form
2. Admins (signers) review and sign via MetaMask
3. Once threshold signatures collected, any admin can execute
4. Transaction submits to blockchain and status updates

### Role Synchronization
- When user assigned admin role → proposes addOwner transaction
- When admin role removed → proposes removeOwner transaction
- Requires existing signers to approve these changes
- Maintains blockchain security model

## Current Implementation Status

### ✅ Complete
- Module scaffolding and configuration
- Plugin architecture (GroupSafeAccount relation)
- Permission system
- Service layer (GroupTreasuryService, TreasuryAccessibilityChecker)
- Access control handlers
- Treasury tab controller with multiple states:
  - No treasury (with "Add Treasury" action)
  - Inaccessible treasury (with reconnection options)
  - Active treasury (full management interface)
- Forms (placeholder implementations):
  - Treasury creation form
  - Transaction proposal form
  - Reconnection form
- Templates for treasury tab and error states
- Routing and local task definitions
- Event subscribers (structure ready for T031 completion)

### ✅ Additional Complete Features
- **T032**: Safe account list integration - Group treasuries appear in user's Safe list
- **T031**: Automatic signer synchronization - Role changes propose Safe signer updates
- **T030**: Wizard step framework - TreasuryWizardStepForm ready for Group wizard integration

### 🚧 In Progress (Next Steps)
- **T030**: Full wizard integration requires Group module version-specific implementation
  - TreasuryWizardStepForm created and ready
  - "Add Treasury" operation added to Groups
  - Wizard hook may need adjustment based on Group module version
- **T033-T037**: Manual browser testing
- **T038**: Export final configuration
- **T040**: Code standards review

### 📝 Form Integration Needed
The forms currently have placeholder implementations. To complete them:
- Integrate Safe deployment UI in `TreasuryCreateForm`
- Reuse Safe transaction form fields in `TreasuryTransactionProposeForm`
- Connect to SafeTransactionService for actual blockchain interactions

## Architecture

### Plugin System
- **GroupSafeAccount**: GroupRelation plugin linking Groups to Safe accounts
- **GroupSafeAccountDeriver**: Creates plugin derivative (single, since no SafeAccount bundles)
- **GroupSafeAccountPermissionProvider**: Defines Group-level permissions

### Services
- `group_treasury.treasury_service`: Core business logic for treasury management
- `group_treasury.accessibility_checker`: Verifies Safe accessibility via API
- `group_treasury.access_control`: Permission checks for treasury operations

### Event System
- `GroupRoleAssignSubscriber`: Listens for admin role assignments
- `GroupRoleRemoveSubscriber`: Listens for admin role removals
- Both propose Safe signer changes via transactions (requires approval)

## Usage Examples

### For Group Creators

**Option 1: Add Treasury During Creation** (if wizard integrated):
1. Create a new Group
2. Complete Safe deployment step in wizard
3. Treasury automatically links to your Group

**Option 2: Add Treasury to Existing Group**:
1. Navigate to your Group page
2. Click "Add Treasury" operation/action
3. Complete Safe deployment form
4. Treasury links to your Group

### For Group Admins
1. Navigate to Group page → Treasury tab
2. Propose transaction: Click "Propose Transaction"
3. Sign transaction: Use MetaMask to sign
4. Execute transaction: Submit to blockchain once threshold met

### For Group Members
1. View treasury: See balance, signers, transactions
2. Propose transaction: Create proposals for admins to review
3. Cannot sign or execute (requires admin role)

## Multi-Group Treasury Support

**Stretch Goal**: The module architecture supports multiple Groups sharing a Safe (group cardinality = 0), though this adds complexity:
- Multiple Groups can reference the same Safe account
- All participating Groups can propose transactions
- Signer management becomes more complex (role changes in any Group affect shared Safe)
- Currently recommended: 1:1 relationship (one treasury per Group)

## Troubleshooting

### Treasury tab shows "No treasury"
- Ensure group_safe_account plugin enabled on Group Type
- Check user has "view group_treasury" permission
- Verify treasury relationship exists: `drush entity:query group_relationship --filter="gid={group_id},plugin_id=group_safe_account:safe_account"`

### Treasury shows as "inaccessible"
- Verify Safe Transaction Service API is accessible
- Check Safe actually exists on blockchain
- Use "Reconnect Treasury" to update network if needed

### Event subscribers not firing
- Verify services registered in group_treasury.services.yml
- Check event names match Group module version
- Complete T031 to flesh out subscriber implementations

## Development

### Running Code Standards
```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/group_treasury/
```

### Testing
Manual browser testing scenarios are defined in:
`specs/004-we-want-to/quickstart.md`

## Contributing

This module follows Drupal coding standards and the constitutional development principles defined in `.specify/memory/constitution.md`.

## License

GPL-2.0-or-later

## Credits

Developed as part of the Drupal Group DAO project, integrating Safe Smart Accounts for decentralized group treasury management.
