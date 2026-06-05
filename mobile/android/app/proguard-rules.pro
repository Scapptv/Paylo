# Sprint 9 M-3: ProGuard rules — release build üçün code shrink + obfuscation.
# Paylo loyalty app specific: Firebase, Flutter platform channels, JSON models.

# --- Flutter / Dart ---
-keep class io.flutter.app.** { *; }
-keep class io.flutter.plugin.** { *; }
-keep class io.flutter.util.** { *; }
-keep class io.flutter.view.** { *; }
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }

# --- Firebase Messaging (FCM) ---
-keep class com.google.firebase.** { *; }
-keep class com.google.android.gms.** { *; }
-dontwarn com.google.firebase.**

# --- flutter_local_notifications ---
-keep class com.dexterous.** { *; }

# --- Kotlin reflection (used by some plugins) ---
-keepattributes *Annotation*, InnerClasses
-dontwarn kotlin.Unit
-dontwarn kotlinx.coroutines.**

# --- AndroidX / Lifecycle (FCM background handler) ---
-keep class androidx.lifecycle.** { *; }

# --- Sentry / crash reporting (gələcəkdə qoşula bilər) ---
-dontwarn io.sentry.**

# Stack trace üçün source file və line number saxla — Sentry/Crashlytics istifadə üçün.
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile
