# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The Group Treasury module integrates Safe Smart Accounts as multi-signature treasuries for Drupal Groups. Built on top of the Safe Smart Accounts module, it enables DAOs and collaborative groups to manage shared funds with on-chain security while maintaining Drupal's workflow and permission systems.

**Core Dependencies:**
- Drupal 10.x
- Group module 2.x (drupal/group ^2.3)
- Safe Smart Accounts module (safe_smart_accounts)
- PHP 8.3+ with GMP extension

**Optional:**
- `social_group_treasury` - Open Social integration module (see below)

## Development Environment

This module is developed within a DDEV environment. From the Drupal root:

```bash
# Start environment
ddev start

# Enable module and clear cache
ddev drush en group_treasury -y && ddev drush cr

# For Open Social installations, also enable the integration module
ddev drush en social_group_treasury -y && ddev drush cr

# Check module status
ddev drush pm:list --filter=group_treasury

# View module logs
ddev drush watchdog:show --type=group_treasury --count=20

# Code standards check
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/group_treasury/

# Export configuration after changes
ddev drush config:export -y
```

## Open Social Integration

For Open Social installations, enable the companion module `social_group_treasury` which provides:
- Block visibility configuration for Open Social blocks (hero, sidebar, etc.)
- Layout adjustments for Open Social's complementary regions
- Workaround for navbar-secondary.js tab rendering issues
- "Propose Transaction" sidebar block matching Open Social's styling

This separation ensures the core `group_treasury` module works with any Drupal + Group installation, while Open Social-specific theming and block configuration is handled by the optional integration module.

## Architecture Overview

### Plugin System (Group Integration)

The module uses Group's plugin architecture to link Safe accounts to Groups:

**GroupSafeAccount** (`src/Plugin/Group/Relation/GroupSafeAccount.php`):
- GroupRelation plugin that establishes the Safe-to-Group relationship
- Uses annotation syntax (@GroupRelationType) for Group 2.x compatibility
- Enforces 1:1 cardinality (one treasury per Group)
- Provides entity_access integration for permission checks

**GroupSafeAccountDeriver** (`src/Plugin/Group/Relation/GroupSafeAccountDeriver.php`):
- Creates plugin derivative for safe_account entity type
- Single derivative since SafeAccount has no bundles

**GroupSafeAccountPermissionProvider** (`src/Plugin/Group/RelationHandler/GroupSafeAccountPermissionProvider.php`):
- Defines Group-level permissions for treasury operations
- Permissions: view, propose transactions, sign, execute, manage

### Service Layer

**GroupTreasuryService** (`src/Service/GroupTreasuryService.php`):
- Core business logic for treasury-group relationships
- Methods: `getTreasury()`, `hasTreasury()`, `addTreasury()`, `removeTreasury()`
- Manages cache invalidation when treasury relationships change

**TreasuryAccessibilityChecker** (`src/Service/TreasuryAccessibilityChecker.php`):
- Validates Safe accessibility via Safe Transaction Service API
- Returns accessibility status and error messages
- Used by controller to determine treasury tab states

### Controller & Routing

**GroupTreasuryController** (`src/Controller/GroupTreasuryController.php`):
- Main controller for `/group/{id}/treasury` tab
- Three states: no treasury, inaccessible treasury, active treasury
- Permission-aware action rendering
- Inline form rendering for treasury creation

**Routes** (`group_treasury.routing.yml`):
- `group_treasury.treasury`: Main treasury tab
- `group_treasury.create`: Treasury creation form
- `group_treasury.reconnect`: Reconnect inaccessible treasury
- `group_treasury.propose_transaction`: Transaction proposal form
- `group_treasury.transaction_view`: Individual transaction view

### Local Tasks & Tab Inheritance

**Challenge**: Treasury child routes (propose, create, reconnect) need to display Group tabs to maintain navigation consistency.

**Solution**: Define child routes as local tasks with `parent_id` referencing the treasury tab. This triggers Drupal's local task inheritance mechanism.

**Implementation** (`group_treasury.links.task.yml`):
```yaml
# Parent tab (visible in Group navigation)
group_treasury.treasury_tab:
  route_name: group_treasury.treasury
  base_route: entity.group.canonical
  title: 'Treasury'
  weight: 50

# Child routes (trigger parent tab inheritance)
group_treasury.propose_task:
  route_name: group_treasury.propose_transaction
  parent_id: group_treasury.treasury_tab
  title: 'Propose Transaction'
  weight: 100
```

**Critical Pattern**: When a child task has `parent_id`, it inherits the `base_route` from its parent, which makes Drupal render the entire tab group on the child route.

### Automatic Signer Synchronization

**Critical Pattern**: The module automatically proposes Safe signer changes when Group roles change. This maintains the principle that on-chain signers must approve all signer list modifications.

**Implementation** (`group_treasury.module`):
- Entity hooks: `hook_group_relationship_insert/update/delete()`
- When admin role assigned → propose `addOwnerWithThreshold` transaction
- When admin role removed → propose `removeOwner` transaction
- When member leaves Group → propose `removeOwner` if they're a signer

**Admin Role Detection**:
The module supports multiple admin role naming patterns:
- `{bundle}-admin` (standard Group module)
- `{bundle}-group_manager` (Open Social)

**Function Encoding**:
```php
// addOwnerWithThreshold(address owner, uint256 _threshold)
// Selector: 0x0d582f13
_group_treasury_encode_add_owner($address, $threshold)

// removeOwner(address prevOwner, address owner, uint256 _threshold)
// Selector: 0xf8dc5dd9
_group_treasury_encode_remove_owner($prev_owner, $address, $threshold)
```

**Safe Linked List Pattern**:
Safe stores owners in a linked list with SENTINEL_OWNER (`0x0000000000000000000000000000000000000001`) as the first element. The `_group_treasury_find_prev_owner()` function calculates the previous owner needed for removeOwner operations.

### Safe Accounts List Integration

**Hook Implementation** (`group_treasury.module`):
- `hook_preprocess_table()` intercepts Safe accounts list page
- Adds Group treasury rows for user's memberships
- Shows "Treasury Signer" role badge with Group context
- Provides "View Treasury" and "Propose Transaction" actions

### Wizard Integration

**Group Creation Wizard** (`group_treasury.module`):
- Third-party setting: `creator_treasury_wizard` on GroupType
- When enabled, redirects new Group creators to treasury deployment
- Form alter hooks intercept group creation form
- Custom submit handlers redirect to `group_treasury.create` route

### Forms

**TreasuryCreateForm** (`src/Form/TreasuryCreateForm.php`):
- Uses `SafeAccountFormTrait` for shared form elements
- AJAX deployment workflow with progress indicators
- Auto-populates Group admin signers

**TreasuryTransactionProposeForm** (`src/Form/TreasuryTransactionProposeForm.php`):
- Uses `SafeTransactionFormTrait` for shared form elements
- Creates SafeTransaction entities for Group treasury

**TreasuryReconnectForm** (`src/Form/TreasuryReconnectForm.php`):
- Updates Safe network configuration
- Handles inaccessible treasury recovery

## Permission System

The module uses two types of permissions that work together:

### Module-Level Permissions

Defined in `group_treasury.group.permissions.yml` for treasury **operations**:
- `view group_treasury`: View treasury tab and transaction history
- `propose group_treasury transactions`: Create transaction proposals
- `sign group_treasury transactions`: Add signatures to pending transactions
- `execute group_treasury transactions`: Submit fully-signed transactions to blockchain
- `manage group_treasury`: Add/remove/reconnect treasury Safe accounts

**Usage**: Route access control (via `TreasuryAccessControlHandler`) and UI element visibility.

### Group Relation Permissions

Defined in `GroupSafeAccountPermissionProvider` for treasury **entity CRUD**:
- `view group_safe_account:safe_account entity`: View the Safe entity relationship
- `create group_safe_account:safe_account entity`: Add a Safe as the group treasury
- `delete group_safe_account:safe_account entity`: Remove the treasury relationship

### Permission Configuration

Both permission types are **configurable per Group Type**:
- Navigate to `/admin/group/types/manage/{type}/permissions`
- Each Group Type has separate role configurations

**Default assignments** (via `hook_install()`):
- **Admin/Manager roles**: All module-level + entity CRUD permissions
- **Members**: View and propose permissions only
- **Outsider/Anonymous**: No permissions (secure default)

## Nonce Management

**Critical**: Safe transactions must be executed in sequential nonce order (0, 1, 2, etc.):

```php
// Calculate next nonce for new transaction
$query = $transaction_storage->getQuery()
  ->condition('safe_account', $safe_account->id())
  ->condition('nonce', NULL, 'IS NOT NULL')  // Include nonce=0
  ->sort('nonce', 'DESC')
  ->range(0, 1);
```

**Common Pitfall**: Using `->condition('nonce', '', '<>')` excludes nonce=0 transactions.

## Cache Management

**Pattern**: Always use entity API methods, never direct SQL:

```php
// Correct - Triggers cache invalidation
$relationship = $group->addRelationship($safe_account, 'group_safe_account:safe_account');
$this->cacheTagsInvalidator->invalidateTags($group->getCacheTags());

// Wrong - Bypasses cache system
ddev drush sqlq "UPDATE group_relationship SET ...";
```

## Testing & Validation

**Common Validation Commands**:
```bash
# Check if Group has treasury
ddev drush entity:query group_relationship --filter="gid=X,plugin_id=group_safe_account:safe_account"

# Verify transaction nonces
ddev drush sql-query "SELECT id, nonce, status, data FROM safe_transaction WHERE safe_account = X ORDER BY nonce"

# Check for duplicate nonces (indicates bug)
ddev drush sql-query "SELECT nonce, COUNT(*) FROM safe_transaction WHERE safe_account = X GROUP BY nonce HAVING COUNT(*) > 1"
```

## Troubleshooting

**Treasury tab shows "No treasury"**:
1. Verify group_safe_account plugin enabled on GroupType
2. Check user has "view group_treasury" permission
3. Query group_relationship table for treasury link

**Treasury shows as "inaccessible"**:
1. Verify Safe Transaction Service API is accessible
2. Check Safe exists on blockchain (use block explorer)
3. Use "Reconnect Treasury" to update network configuration
4. Check Safe status: only 'active' Safes are checked for accessibility

**Automatic signer sync not working**:
1. Verify Group has active treasury (not pending/error status)
2. Check user has `field_ethereum_address` populated
3. Verify user's role matches admin role pattern: `{bundle}-admin` or `{bundle}-group_manager`
4. Check for duplicate nonce issues in safe_transaction table

## Multi-Group Treasury Support (Stretch Goal)

The architecture supports multiple Groups sharing a single Safe (group cardinality = 0), though this is not currently recommended:
- Multiple Groups can reference the same SafeAccount entity
- All participating Groups can propose transactions
- Signer management complexity increases
- **Current recommendation**: 1:1 relationship (one treasury per Group)
