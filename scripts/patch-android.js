// CI helper: `npx cap add android` ke baad generated android/ project mein fixed signing key lagata hai.
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const appDir = path.join(root, 'android', 'app');

const gradlePath = path.join(appDir, 'build.gradle');
let gradle = fs.readFileSync(gradlePath, 'utf8');
if (!gradle.includes('signingConfigs')) {
  const signing = `
    signingConfigs {
        release {
            storeFile file(System.getenv("KS_PATH") ?: "../../android-signing/mittu-shop.keystore")
            storePassword System.getenv("KS_PASS") ?: "mittushop123"
            keyAlias System.getenv("KS_ALIAS") ?: "mittushop"
            keyPassword System.getenv("KS_KEY_PASS") ?: "mittushop123"
        }
    }
    buildTypes {`;
  if (!/\n\s*buildTypes\s*\{/.test(gradle)) throw new Error('buildTypes block nahi mila in app/build.gradle');
  gradle = gradle.replace(/\n\s*buildTypes\s*\{/, signing);
  if (!/release\s*\{/.test(gradle)) throw new Error('release block nahi mila');
  gradle = gradle.replace(/(buildTypes\s*\{\s*release\s*\{)/, '$1\n            signingConfig signingConfigs.release');
  fs.writeFileSync(gradlePath, gradle);
}
console.log('Android project patch ho gaya');
