import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Paylo dark palette — eyni HTML mock-larındakı CSS variables ilə uyğun.
abstract final class AppColors {
  static const bg        = Color(0xFF0A0B0F);
  static const bg2       = Color(0xFF0F1117);
  static const surface   = Color(0xFF14161E);
  static const surface2  = Color(0xFF1B1E29);
  static const surface3  = Color(0xFF232735);
  static const border    = Color(0xFF242838);
  static const border2   = Color(0xFF2F3445);
  static const text      = Color(0xFFE8EAF0);
  static const text2     = Color(0xFFC5C9D6);
  static const muted     = Color(0xFF7A8094);

  static const accent       = Color(0xFFC8FF3D);
  static const accentOrange = Color(0xFFFF7A4D);
  static const accentBlue   = Color(0xFF6C8EEF);
  static const accentPurple = Color(0xFFB794F6);

  static const danger  = Color(0xFFFF5470);
  static const success = Color(0xFF58E1A3);
  static const warning = Color(0xFFFFC857);
}

abstract final class AppTextStyles {
  // Display / serif
  static TextStyle display(double size, {FontWeight weight = FontWeight.w300, Color? color, double letterSpacing = -0.02}) =>
      GoogleFonts.fraunces(
        fontSize: size,
        fontWeight: weight,
        color: color ?? AppColors.text,
        letterSpacing: letterSpacing,
        height: 1.0,
      );

  // Body / sans
  static TextStyle body(double size, {FontWeight weight = FontWeight.w400, Color? color}) =>
      GoogleFonts.manrope(
        fontSize: size,
        fontWeight: weight,
        color: color ?? AppColors.text,
        height: 1.5,
      );

  // Mono / mətn etiketi, kod
  static TextStyle mono(double size, {FontWeight weight = FontWeight.w500, Color? color, double letterSpacing = 0.08}) =>
      GoogleFonts.jetBrainsMono(
        fontSize: size,
        fontWeight: weight,
        color: color ?? AppColors.muted,
        letterSpacing: letterSpacing,
        height: 1.3,
      );
}

abstract final class AppTheme {
  static ThemeData dark() {
    return ThemeData(
      brightness: Brightness.dark,
      scaffoldBackgroundColor: AppColors.bg,
      primaryColor: AppColors.accent,
      colorScheme: const ColorScheme.dark(
        primary: AppColors.accent,
        onPrimary: AppColors.bg,
        secondary: AppColors.accentBlue,
        surface: AppColors.surface,
        onSurface: AppColors.text,
        error: AppColors.danger,
      ),
      textTheme: TextTheme(
        displayLarge:  AppTextStyles.display(48),
        displayMedium: AppTextStyles.display(36),
        displaySmall:  AppTextStyles.display(28),
        headlineLarge: AppTextStyles.display(24, weight: FontWeight.w600),
        bodyLarge:     AppTextStyles.body(16),
        bodyMedium:    AppTextStyles.body(14),
        bodySmall:     AppTextStyles.body(12, color: AppColors.muted),
        labelSmall:    AppTextStyles.mono(11),
      ),
      iconTheme: const IconThemeData(color: AppColors.text2, size: 22),
      dividerTheme: const DividerThemeData(color: AppColors.border, thickness: 1, space: 1),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surface,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border:        _inputBorder(AppColors.border),
        enabledBorder: _inputBorder(AppColors.border),
        focusedBorder: _inputBorder(AppColors.accent, width: 1.5),
        errorBorder:   _inputBorder(AppColors.danger),
        focusedErrorBorder: _inputBorder(AppColors.danger, width: 1.5),
        labelStyle: AppTextStyles.mono(11, color: AppColors.muted),
        hintStyle:  AppTextStyles.body(14, color: AppColors.muted),
        errorStyle: AppTextStyles.mono(11, color: AppColors.danger),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.accent,
          foregroundColor: AppColors.bg,
          elevation: 0,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
          shape: const RoundedRectangleBorder(borderRadius: BorderRadius.zero),
          textStyle: AppTextStyles.mono(12, weight: FontWeight.w600, color: AppColors.bg, letterSpacing: 0.16),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.text2,
          side: const BorderSide(color: AppColors.border),
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
          shape: const RoundedRectangleBorder(borderRadius: BorderRadius.zero),
          textStyle: AppTextStyles.mono(12, weight: FontWeight.w600, letterSpacing: 0.16),
        ),
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: AppColors.surface,
        selectedItemColor: AppColors.accent,
        unselectedItemColor: AppColors.muted,
        type: BottomNavigationBarType.fixed,
        showUnselectedLabels: true,
        elevation: 0,
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.bg,
        foregroundColor: AppColors.text,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: AppTextStyles.display(20, weight: FontWeight.w600, letterSpacing: -0.01),
      ),
    );
  }

  static OutlineInputBorder _inputBorder(Color color, {double width = 1}) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.zero,
        borderSide: BorderSide(color: color, width: width),
      );
}
