An RSS feed generator for the pirate bay.
It should work for both browsing and searching.
Pages are cached for 300 seconds.

GET options
   path
      the path from the original url
      "/browse/104"
         from "thepiratebay.se/browse/104"
      "/search/completely legal linux iso"
         from "thepiratebay.se/search/completely legal linux iso"
   key
      used to restrict the use of the script
      must match the content of "config/key.txt"

about key protection
to avoid unwanted use of the script by your site's visitors,
create config/key.txt and put a password in it.
When requesting a feed with "path", add "key" to the url and input the password.
ex: ?path=/browse/104&key=topSecret