# Upgrade Notes

## Upgrade to 2026.1.0

### PHP & Pimcore Version Requirements
- Added support for `PHP` `8.5`.
- Removed support for `PHP` `8.3`.
- Added requirements for `pimcore/studio-backend-bundle` and `pimcore/studio-ui-bundle` 

### License Change
- License switched from GPL-3.0 to the Pimcore Open Core License (POCL).

### Return Type and Method Signature Changes
- `PimcorePaymentProviderPayPalSmartPaymentButtonExtension::load()`: Added native return type `void`.
