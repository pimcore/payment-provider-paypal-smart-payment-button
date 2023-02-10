## Changelog
### 2.0
- Removed deprecated `initPayment` method.

### 1.0.5
- Added deprecation trigger to `initPayment`, which is [deprecated and removed](https://github.com/pimcore/pimcore/blob/478c011b8dd4b2e8fd5e1e0739da7a0898a31273/doc/Development_Documentation/23_Installation_and_Upgrade/09_Upgrade_Notes/README.md?plain=1#L665) since Pimcore 10. In 2.0 it will be replaced by `createOrder`
