# WordPress Playground demo

`blueprint.json` spins up a pristine WordPress with Agentyllo 0.1.0 (installed from
https://www.agentyllo.com/downloads/agentyllo-0.1.0.zip, CORS-enabled), seeds three
demo pages and indexes them synchronously — the classic chatbot answers immediately,
no API key involved.

**Share link (tested 2026-08-21: install, index, in-scope answer with exact price +
link, off-topic refusal, Art. 50 footer):**

https://playground.wordpress.net/#%7B%22%24schema%22%3A%22https%3A%2F%2Fplayground.wordpress.net%2Fblueprint-schema.json%22%2C%22landingPage%22%3A%22%2F%3Fagentyllo-demo%3D1%22%2C%22preferredVersions%22%3A%7B%22php%22%3A%228.2%22%2C%22wp%22%3A%22latest%22%7D%2C%22features%22%3A%7B%22networking%22%3Atrue%7D%2C%22steps%22%3A%5B%7B%22step%22%3A%22login%22%2C%22username%22%3A%22admin%22%2C%22password%22%3A%22password%22%7D%2C%7B%22step%22%3A%22installPlugin%22%2C%22pluginData%22%3A%7B%22resource%22%3A%22url%22%2C%22url%22%3A%22https%3A%2F%2Fwww.agentyllo.com%2Fdownloads%2Fagentyllo-0.1.0.zip%22%7D%2C%22options%22%3A%7B%22activate%22%3Atrue%7D%7D%2C%7B%22step%22%3A%22setSiteOptions%22%2C%22options%22%3A%7B%22blogname%22%3A%22Agentyllo%20Demo%20Store%22%2C%22blogdescription%22%3A%22Classic%20agents%20answer%20from%20your%20content%20-%20no%20API%20key%20needed%22%7D%7D%2C%7B%22step%22%3A%22runPHP%22%2C%22code%22%3A%22%3C%3Fphp%20require%20%27%2Fwordpress%2Fwp-load.php%27%3B%20wp_insert_post%28array%28%27post_title%27%3D%3E%27Shipping%20%26%20Returns%27%2C%27post_status%27%3D%3E%27publish%27%2C%27post_type%27%3D%3E%27page%27%2C%27post_content%27%3D%3E%27%3Ch2%3EShipping%3C%2Fh2%3E%3Cp%3EOrders%20ship%20within%2024%20hours.%20Delivery%20to%20Italy%20takes%202-3%20working%20days%2C%20Europe%204-6%20days.%20Express%20delivery%20in%2024%20hours%20costs%209%20euro.%3C%2Fp%3E%3Ch2%3EReturns%3C%2Fh2%3E%3Cp%3EYou%20can%20return%20any%20item%20within%2030%20days.%20Refunds%20are%20processed%20in%205%20working%20days.%3C%2Fp%3E%27%29%29%3B%20wp_insert_post%28array%28%27post_title%27%3D%3E%27Contact%27%2C%27post_status%27%3D%3E%27publish%27%2C%27post_type%27%3D%3E%27page%27%2C%27post_content%27%3D%3E%27%3Cp%3EEmail%3A%20hello%40example.test%20-%20Phone%3A%20%2B39%20055%20123456.%20Opening%20hours%3A%20Monday%20to%20Friday%2C%209%3A00-18%3A00.%3C%2Fp%3E%27%29%29%3B%20wp_insert_post%28array%28%27post_title%27%3D%3E%27About%20us%27%2C%27post_status%27%3D%3E%27publish%27%2C%27post_type%27%3D%3E%27page%27%2C%27post_content%27%3D%3E%27%3Cp%3EWe%20are%20a%20family-run%20store%20founded%20in%202012%2C%20specialised%20in%20handmade%20leather%20goods%20crafted%20in%20Florence.%3C%2Fp%3E%27%29%29%3B%22%7D%2C%7B%22step%22%3A%22runPHP%22%2C%22code%22%3A%22%3C%3Fphp%20require%20%27%2Fwordpress%2Fwp-load.php%27%3B%20do_action%28%27plugins_loaded%27%29%3B%20do_action%28%27init%27%29%3B%20if%20%28%20class_exists%28%27Agentyllo%5C%5C%5C%5CPlugin%27%29%20%26%26%20Agentyllo%5C%5CPlugin%3A%3Ainstance%28%29%20%29%20%7B%20%24im%20%3D%20Agentyllo%5C%5CPlugin%3A%3Ainstance%28%29-%3Econtainer%28%29-%3Eget%28%20Agentyllo%5C%5CKB%5C%5CIndexer%5C%5CIndexManager%3A%3Aclass%20%29%3B%20%24im-%3Erun_full_crawl%28%27site%27%2C%27%27%2C0%29%3B%20%24im-%3Erun_full_crawl%28%27post%27%2C%27page%27%2C0%29%3B%20%24im-%3Erun_full_crawl%28%27menu%27%2C%27%27%2C0%29%3B%20%7D%22%7D%5D%7D

Note: passing the blueprint inline in the URL fragment is the reliable method;
`?blueprint-url=` was ignored in our tests. Regenerate the link after editing
blueprint.json with:

```bash
python3 -c "import json,urllib.parse;print('https://playground.wordpress.net/#'+urllib.parse.quote(json.dumps(json.load(open('tools/playground/blueprint.json')),separators=(',',':')),safe=''))"
```

When the plugin is approved on WordPress.org, switch `pluginData` to
`{"resource":"wordpress.org/plugins","slug":"agentyllo"}`.
