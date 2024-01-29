# Upgrading

## From v1 to v2

While this  should be a pretty easy upgrade, you should be aware of some of the changes that have been made.

- Minimum PHP version is now 8.1 and minimum Laravel version is now 10.
- The configuration slightly changed. Check the [configuration](config/spectator.php) and update your configuration accordingly.
- Calling `$response->assertValidRequest()` now fails if the openapi spec is not found or invalid, which was not the case previously.
- The specification must now be found and valid when calling `$response->assertInvalidRequest()` or `$response->assertInvalidResponse()`.
Previously, the request or response was considered invalid if the spec was not found or invalid, thus `$response->assertInvalidRequest()` or `$response->assertInvalidResponse()` did pass.
It will now fail.
- The validation is stricter than before. Tests that were passing before might now fail. You may need to update your tests or your openapi specifications.
