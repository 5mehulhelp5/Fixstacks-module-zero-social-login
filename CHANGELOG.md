# Changelog: zerofront/module-zero-social-login

## 1.3.6

- An email match alone no longer signs anyone in to an existing account. When Google or Apple report a verified email that belongs to an account with a password, the customer signs in with that password in the same browser within 15 minutes and the service is connected then (no email is sent).
- An account without a password (made by a social sign-in) gets an emailed link instead: valid one hour, used once, and only the button on its page connects the service, so a mail scanner opening the link changes nothing. New setting Stores > Configuration > Zero > Social Login > **Connect Link Email**.
- When the account's email was never confirmed, connecting through the link also removes its other sign-in services and signs it out everywhere, so whoever registered with someone else's address loses the account.
- After a service is connected to an account that already had another one, the message lists every connected service, so a customer notices one they don't recognise.
- Facebook, which doesn't say whether an email is verified, still never takes over an existing account.
- `setup:upgrade` adds the table `zero_social_login_link`. The login link's token is kept only as a SHA-256 and is redacted in Zero Mail's email log.
- A provider's callback with array-valued parameters no longer causes a 500.

Earlier releases (1.3.5 and before): see the Git tags.
