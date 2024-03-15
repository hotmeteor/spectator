# Upgrading

## From v1 to v2

While this should be a pretty easy upgrade, you should be aware of some of the changes that have been made.

- Minimum PHP version is now 8.1 and minimum Laravel version is now 10.
- The configuration slightly changed. Check the [configuration](config/spectator.php) and update your configuration
  accordingly.
- Calling `$response->assertValidRequest()` now fails if the openapi spec is not found or invalid, which was not the
  case previously.
- The specification must now be found and valid when calling `$response->assertInvalidRequest()`
  or `$response->assertInvalidResponse()`.
  
  Previously, the request or response was considered invalid if the spec was not found or invalid,
  thus `$response->assertInvalidRequest()` or `$response->assertInvalidResponse()` did pass.
  It will now fail.
- The `$response->assertInvalidRequest()` and `$response->assertInvalidResponse()` previously did not fail if called on
  a perfectly valid http call. It will now fail.
- When providing a http code to `$response->assertValidResponse($code)` or `$response->assertInvalidResponse($code)`, we
  now prioritize the validation of the actual http code over the validation of the specification.
- Spectator now ignores the charset definition in the `Content-Type` header of the response in order to find the 
  response definition in the openapi. If you previously defined your openapi with the full `Content-Type` header, you will have to delete the charset part.
  ```diff
  /users:
    get:
      summary: Get users
      tags: []
      responses:
        '200':
          description: OK
          content:
  -         application/json; charset=utf-8:
  +         application/json:
              schema:
                type: object
                properties:
                  id:
                    type: integer
  ```
- The validation is stricter than before. Tests that were passing before might now fail. You may need to update your
  tests or your openapi specifications.
- A non-empty response body will be now considered invalid against a specification not defining a response content.
- Internal modifications were made. If you extend Spectator, you may need to update your code.
