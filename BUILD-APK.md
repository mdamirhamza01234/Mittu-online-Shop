# Mittu Online Shop APK

APK GitHub Actions se banta hai (Capacitor). App `https://mdamirhamza01234.github.io/Mittu-online-Shop/` kholta hai,
isliye shop update hote hi app mein bhi update aa jaata hai — har baar naya APK nahi chahiye.

1. Repo -> **Actions** -> **Build Android APK** -> **Run workflow**.
2. 5-8 minute baad **Releases -> "Mittu Online Shop APK (latest)"** se `Mittu-Online-Shop.apk` download karke install karein.

`android-signing/mittu-shop.keystore` APK ko hamesha ek hi signature deta hai (naya APK purane ke upar install hota hai).
Repo public hai, isliye baad mein is keystore ko GitHub Secrets mein shift karna behtar hai.
