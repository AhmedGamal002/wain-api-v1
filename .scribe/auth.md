# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

الـ token بيرجع من <code>POST /auth/register</code> أو <code>POST /auth/login</code>. الـ endpoints العامة بتشتغل من غيره، ولو بعتّه بيتفعّل <code>is_saved</code>.
