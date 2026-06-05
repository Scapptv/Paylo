import java.util.Properties
import java.io.FileInputStream

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Sprint 9 M-3: Release signing konfiqurasiyası.
//
// `android/key.properties` faylı `.gitignore`-dadır və komand:
//   keytool -genkey -v -keystore android/app/upload-keystore.jks \
//     -keyalg RSA -keysize 2048 -validity 10000 -alias upload
//
// `android/key.properties` məzmunu:
//   storePassword=<KEYSTORE_PASS>
//   keyPassword=<KEY_PASS>
//   keyAlias=upload
//   storeFile=upload-keystore.jks   (app/ qovluğuna nisbi)
//
// Production build: `flutter build appbundle --release` → AAB Play Store-a yüklənir.
// Fayl tapılmadıqda debug key-ə fallback edilir ki, `flutter run --release` dev-də işləsin.

val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

android {
    namespace = "az.paylo.app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        // Required by flutter_local_notifications (uses java.time.* on minSdk < 26)
        isCoreLibraryDesugaringEnabled = true
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        applicationId = "az.paylo.app"
        // flutter_local_notifications scheduled notifications require API 21+
        minSdk = maxOf(flutter.minSdkVersion, 21)
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        multiDexEnabled = true
    }

    signingConfigs {
        if (keystorePropertiesFile.exists()) {
            create("release") {
                keyAlias = keystoreProperties["keyAlias"] as String?
                keyPassword = keystoreProperties["keyPassword"] as String?
                storeFile = keystoreProperties["storeFile"]?.let { file(it as String) }
                storePassword = keystoreProperties["storePassword"] as String?
            }
        }
    }

    buildTypes {
        release {
            // Sprint 9 M-3: production build üçün release keystore; əgər
            // `android/key.properties` yoxdursa, debug key fallback olur.
            signingConfig = if (keystorePropertiesFile.exists()) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }

            // Code shrinking + obfuscation — production üçün vacibdir.
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
}

flutter {
    source = "../.."
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}
