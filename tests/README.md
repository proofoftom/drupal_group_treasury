# Group Treasury Module Tests

This directory contains comprehensive test coverage for the Group Treasury module.

## Test Organization

### Unit Tests (`src/Unit/`)
Unit tests focus on isolated testing of individual classes without Drupal dependencies:

- **GroupTreasuryServiceTest.php** - Tests the core treasury service methods
- **TreasuryAccessibilityCheckerTest.php** - Tests Safe accessibility checking logic

### Kernel Tests (`src/Kernel/`)
Kernel tests run with minimal Drupal bootstrap and test integration with Drupal APIs:

- **GroupTreasuryServiceIntegrationTest.php** - Tests treasury service with real entities
- **GroupSafeAccountPluginTest.php** - Tests the Group plugin discovery and configuration
- **AutomaticSignerSyncTest.php** - Tests automatic signer synchronization when roles change
- **GroupTreasuryPermissionsTest.php** - Tests permission system integration
- **ModuleHooksTest.php** - Tests module hooks and helper functions

### Functional Tests (`src/Functional/`)
Functional tests use full Drupal installation with browser simulation:

- **GroupTreasuryControllerTest.php** - Tests treasury tab rendering and access control
- **TreasuryFormsTest.php** - Tests treasury management forms
- **TreasuryWizardIntegrationTest.php** - Tests wizard integration with group creation

## Running Tests

### From DDEV Environment

From the Drupal root (`/home/proofoftom/Code/drupal-group-dao`):

```bash
# Run all Group Treasury tests
ddev exec vendor/bin/phpunit web/modules/custom/group_treasury/tests

# Run specific test types
ddev exec vendor/bin/phpunit web/modules/custom/group_treasury/tests/src/Unit
ddev exec vendor/bin/phpunit web/modules/custom/group_treasury/tests/src/Kernel
ddev exec vendor/bin/phpunit web/modules/custom/group_treasury/tests/src/Functional

# Run a specific test class
ddev exec vendor/bin/phpunit web/modules/custom/group_treasury/tests/src/Kernel/AutomaticSignerSyncTest.php

# Run with coverage report (requires xdebug)
ddev exec vendor/bin/phpunit --coverage-html coverage web/modules/custom/group_treasury/tests
```

### Standalone (if Drupal core is installed)

```bash
cd /path/to/drupal/root
vendor/bin/phpunit modules/custom/group_treasury/tests
```

## Test Coverage

### Core Services
- ✅ GroupTreasuryService - All methods (getTreasury, addTreasury, removeTreasury, etc.)
- ✅ TreasuryAccessibilityChecker - All accessibility check scenarios

### Plugin System
- ✅ Plugin discovery and installation
- ✅ Default configuration (1:1 cardinality)
- ✅ Permission provider integration
- ✅ Entity access integration

### Controllers & Routes
- ✅ Treasury tab - all states (no treasury, pending, inaccessible, active)
- ✅ Access control for different user roles
- ✅ Cache tag validation
- ✅ Title callbacks

### Forms
- ✅ Treasury create form access and display
- ✅ Treasury reconnect form
- ✅ Transaction propose form
- ✅ Access control without treasury

### Automatic Signer Synchronization
- ✅ Adding admin role proposes addOwnerWithThreshold transaction
- ✅ Removing admin role proposes removeOwner transaction
- ✅ Member leaving group triggers removeOwner if signer
- ✅ No transaction for users without Ethereum address
- ✅ No transaction for pending treasuries
- ✅ Sequential nonce management
- ✅ No duplicate nonces

### Integration Points
- ✅ Group type third-party settings (wizard)
- ✅ Theme hook registration
- ✅ Entity hooks for group relationships
- ✅ Permission system integration

## Test Requirements

Tests require the following to run successfully:

### Drupal Modules
- group
- safe_smart_accounts
- system
- user
- field
- node (for functional tests)

### PHP Extensions
- gmp (for cryptographic operations)
- bcmath (for big number operations)

### Optional
- xdebug (for coverage reports)

## Writing New Tests

### Unit Tests
```php
<?php
namespace Drupal\Tests\group_treasury\Unit;

use Drupal\Tests\UnitTestCase;

class MyServiceTest extends UnitTestCase {
  protected function setUp(): void {
    parent::setUp();
    // Setup mocks
  }

  public function testMyMethod() {
    // Test logic
  }
}
```

### Kernel Tests
```php
<?php
namespace Drupal\Tests\group_treasury\Kernel;

use Drupal\KernelTests\KernelTestBase;

class MyIntegrationTest extends KernelTestBase {
  protected static $modules = ['group', 'safe_smart_accounts', 'group_treasury'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('group');
    // Install other schemas
  }

  public function testIntegration() {
    // Test with real entities
  }
}
```

### Functional Tests
```php
<?php
namespace Drupal\Tests\group_treasury\Functional;

use Drupal\Tests\BrowserTestBase;

class MyFunctionalTest extends BrowserTestBase {
  protected $defaultTheme = 'stark';
  protected static $modules = ['group', 'safe_smart_accounts', 'group_treasury'];

  public function testUserInterface() {
    $user = $this->drupalCreateUser(['view group_treasury']);
    $this->drupalLogin($user);
    $this->drupalGet('/group/1/treasury');
    $this->assertSession()->statusCodeEquals(200);
  }
}
```

## Continuous Integration

Tests should be run in CI/CD pipelines before merging changes. Recommended workflow:

1. Run unit tests (fastest)
2. Run kernel tests (medium speed)
3. Run functional tests (slowest)
4. Generate coverage report
5. Fail build if coverage drops below threshold

## Known Limitations

### Safe API Mocking
Some tests (especially functional tests with active Safes) may fail if the Safe Transaction Service API is not accessible or not mocked. In production test environments, consider:

- Mocking the SafeApiService
- Using test doubles for external API calls
- Setting up a test Safe Transaction Service instance

### Field Dependencies
Tests assume `field_ethereum_address` exists on user entities. If your installation uses a different field name, update the tests accordingly.

## Contributing

When adding new features to the module:

1. Write tests FIRST (TDD approach)
2. Ensure all new public methods have test coverage
3. Test both success and failure scenarios
4. Update this README with new test files
5. Run the full test suite before submitting PRs

## Troubleshooting

### "Call to undefined function" errors
- Ensure all required modules are enabled in `$modules` array
- Install necessary schemas in `setUp()` method

### "Entity type not found" errors
- Add `$this->installEntitySchema('entity_type_name')` in `setUp()`

### Permission errors in functional tests
- Verify user has correct permissions in `drupalCreateUser()`
- Check group membership and roles are set correctly

### Database errors
- Ensure `$this->installSchema('module', ['table'])` is called for custom tables
- Install 'system' sequences if using entity IDs

## Performance

Approximate test execution times (may vary):

- Unit tests: < 1 second
- Kernel tests: 2-5 seconds each
- Functional tests: 5-15 seconds each

Total suite: ~1-2 minutes

## Additional Resources

- [Drupal PHPUnit documentation](https://www.drupal.org/docs/automated-testing/phpunit-in-drupal)
- [Group module testing examples](https://git.drupalcode.org/project/group/-/tree/3.x/tests)
- [Safe Smart Accounts module](https://github.com/proofoftom/safe_smart_accounts)
